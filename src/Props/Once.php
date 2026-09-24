<?php

declare(strict_types=1);

namespace Bridge\Props;

use DateInterval;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Sent once and then reused by the client until it expires (spec/page.md
 * §11). A client that holds the value lists its key in `X-Bridge-Once` and
 * the value is left out. Resolved like a plain prop in JSON mode.
 */
final class Once extends PropHint
{
    public function __construct(
        mixed $value,
        public readonly ?string $key = null,
        public readonly DateInterval|int|null $ttl = null,
        public readonly bool $fresh = false,
    ) {
        if ($value instanceof PropHint) {
            throw new InvalidArgumentException('Bridge::once() cannot wrap another prop hint (lazy, defer, always, merge or once).');
        }

        if ($key !== null) {
            self::assertKey($key);
        }

        parent::__construct($value);
    }

    /**
     * Send the value even to a client that holds it, for example after the
     * server knows it changed. Returns a copy: shared hints outlive requests.
     */
    public function fresh(bool $fresh = true): self
    {
        return new self($this->value, $this->key, $this->ttl, $fresh);
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
