<?php

declare(strict_types=1);

namespace Bridge\Stream;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Caps concurrent streams per user (or IP for guests) using cache counters.
 */
final class ConnectionLimiter
{
    public function __construct(
        private readonly Cache $cache,
        private readonly int $max,
        private readonly int $ttlSeconds,
    ) {}

    public function acquire(string $subject): bool
    {
        if ($this->max <= 0) {
            return true;
        }

        $key = $this->key($subject);
        $this->cache->add($key, 0, $this->ttlSeconds);
        $count = (int) $this->cache->increment($key);

        if ($count > $this->max) {
            $this->cache->decrement($key);

            return false;
        }

        return true;
    }

    public function release(string $subject): void
    {
        if ($this->max <= 0) {
            return;
        }

        $key = $this->key($subject);

        if ((int) $this->cache->get($key, 0) > 0) {
            $this->cache->decrement($key);
        }
    }

    public function current(string $subject): int
    {
        return (int) $this->cache->get($this->key($subject), 0);
    }

    private function key(string $subject): string
    {
        return 'bridge:streams:'.sha1($subject);
    }
}
