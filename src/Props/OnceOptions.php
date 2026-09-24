<?php

declare(strict_types=1);

namespace Bridge\Props;

use DateInterval;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * How a once prop is kept by the client (spec/page.md §11).
 */
final class OnceOptions
{
    public function __construct(
        public readonly ?string $key = null,
        public readonly DateInterval|int|null $ttl = null,
        public readonly bool $fresh = false,
    ) {
        if ($key !== null) {
            self::assertKey($key);
        }
    }

    /** The once key: the prop name unless another was given. */
    public function keyFor(string $prop): string
    {
        $key = $this->key ?? $prop;
        self::assertKey($key);

        return $key;
    }

    /** Milliseconds since the epoch when a value sent now stops being reusable. */
    public function expiresAt(): ?int
    {
        if ($this->ttl === null) {
            return null;
        }

        $now = Carbon::now();
        $expires = is_int($this->ttl) ? $now->copy()->addSeconds($this->ttl) : $now->copy()->add($this->ttl);

        return (int) $expires->getPreciseTimestamp(3);
    }

    private static function assertKey(string $key): void
    {
        if ($key === '' || preg_match('/[,\s]/', $key) === 1) {
            throw new InvalidArgumentException("Once key [{$key}] must be non-empty and contain no commas or whitespace.");
        }
    }
}
