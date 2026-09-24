<?php

declare(strict_types=1);

namespace Bridge\Props;

use DateInterval;
use InvalidArgumentException;

/**
 * A plain prop with the once modifier (spec/page.md §11): `Bridge::once()`.
 * Sent once and then reused by the client until it expires; a client that
 * holds the value lists its key in `X-Bridge-Once` and the value is left
 * out. Resolved like a plain prop in JSON mode.
 */
final class Once extends PropHint
{
    public function __construct(
        mixed $value,
        ?string $key = null,
        DateInterval|int|null $ttl = null,
        bool $fresh = false,
    ) {
        if ($value instanceof PropHint) {
            throw new InvalidArgumentException('Bridge::once() cannot wrap another prop hint; chain ->once() on it instead.');
        }

        parent::__construct($value);
        $this->initOnce(new OnceOptions($key, $ttl, $fresh));
    }
}
