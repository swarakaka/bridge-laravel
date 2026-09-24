<?php

declare(strict_types=1);

namespace Bridge\Stream;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Caps concurrent streams per user (or IP for guests).
 *
 * With a lock-capable cache store (array, file, database, redis, memcached,
 * dynamodb) each stream holds a lease that expires unless renewed. A live
 * subscription renews it on every heartbeat, so a worker killed mid-stream
 * (SIGKILL, request_terminate_timeout) frees its slot within a few heartbeats
 * instead of holding it for the whole stream lifetime. Other stores fall back
 * to a plain counter that expires after `ttlSeconds`.
 */
final class ConnectionLimiter
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param  (Closure(): int)|null  $clock  seconds; tests pass a fake
     */
    public function __construct(
        private readonly Cache $cache,
        private readonly int $max,
        private readonly int $ttlSeconds,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Take a slot for $leaseSeconds (default: the whole stream lifetime).
     * Returns a lease id, or null when the subject is at its limit.
     */
    public function acquire(string $subject, ?int $leaseSeconds = null): ?string
    {
        if ($this->max <= 0) {
            return 'unlimited';
        }

        $lease = bin2hex(random_bytes(8));
        $lifetime = max(1, $leaseSeconds ?? $this->ttlSeconds);

        $locks = $this->locks();

        if ($locks === null) {
            return $this->acquireCounter($subject) ? $lease : null;
        }

        return $this->withLeases($locks, $subject, function (array $leases) use ($lease, $lifetime): array {
            if (count($leases) >= $this->max) {
                return [$leases, null];
            }

            $leases[$lease] = ($this->clock)() + $lifetime;

            return [$leases, $lease];
        });
    }

    /** Extend a lease; live subscriptions call this on every heartbeat. */
    public function renew(string $subject, ?string $lease, int $leaseSeconds): void
    {
        $locks = $this->locks();

        if ($this->max <= 0 || $lease === null || $locks === null) {
            return;
        }

        $this->withLeases($locks, $subject, function (array $leases) use ($lease, $leaseSeconds): array {
            // A lease that already expired was given away; do not resurrect it over the limit.
            if (isset($leases[$lease]) || count($leases) < $this->max) {
                $leases[$lease] = ($this->clock)() + max(1, $leaseSeconds);
            }

            return [$leases, null];
        });
    }

    public function release(string $subject, ?string $lease = null): void
    {
        if ($this->max <= 0) {
            return;
        }

        $locks = $this->locks();

        if ($locks === null) {
            $this->releaseCounter($subject);

            return;
        }

        $this->withLeases($locks, $subject, function (array $leases) use ($lease): array {
            if ($lease !== null) {
                unset($leases[$lease]);
            } elseif ($leases !== []) {
                // No lease given (legacy callers): free the one closest to expiring.
                asort($leases);
                unset($leases[array_key_first($leases)]);
            }

            return [$leases, null];
        });
    }

    public function current(string $subject): int
    {
        if ($this->locks() === null) {
            return (int) $this->cache->get($this->key($subject), 0);
        }

        return count($this->live($this->cache->get($this->key($subject), [])));
    }

    private function locks(): ?LockProvider
    {
        $store = $this->cache->getStore();

        return $store instanceof LockProvider ? $store : null;
    }

    /**
     * Read, change and write the subject's leases under a lock.
     *
     * @template T
     *
     * @param  Closure(array<string, int>): array{0: array<string, int>, 1: T}  $change
     * @return T
     */
    private function withLeases(LockProvider $locks, string $subject, Closure $change): mixed
    {
        $key = $this->key($subject);

        return $locks->lock($key.':lock', 5)->block(3, function () use ($key, $change): mixed {
            [$leases, $result] = $change($this->live($this->cache->get($key, [])));

            if ($leases === []) {
                $this->cache->forget($key);
            } else {
                $this->cache->put($key, $leases, max(1, max($leases) - ($this->clock)()));
            }

            return $result;
        });
    }

    /**
     * @return array<string, int>
     */
    private function live(mixed $leases): array
    {
        if (! is_array($leases)) {
            return [];
        }

        $now = ($this->clock)();

        return array_filter(
            array_filter($leases, 'is_int'),
            static fn (int $expires): bool => $expires > $now,
        );
    }

    private function acquireCounter(string $subject): bool
    {
        $key = $this->key($subject);
        $this->cache->add($key, 0, $this->ttlSeconds);
        $count = (int) $this->cache->increment($key);

        if ($count > $this->max) {
            $this->cache->decrement($key);

            return false;
        }

        return true;
    }

    private function releaseCounter(string $subject): void
    {
        $key = $this->key($subject);

        if ((int) $this->cache->get($key, 0) <= 0) {
            return;
        }

        // get-then-decrement is not atomic: two releases can both pass the check.
        // Undo a decrement that went below zero instead of trusting the read.
        $count = $this->cache->decrement($key);

        if ($count !== false && (int) $count < 0) {
            $this->cache->increment($key, -(int) $count);
        }
    }

    private function key(string $subject): string
    {
        return 'bridge:streams:'.sha1($subject);
    }
}
