<?php

declare(strict_types=1);

namespace Bridge\Stream\Bus;

/**
 * Position in the bus. `fallback` applies to every channel without an explicit
 * position; drivers with a global order (database, sync) use only the fallback.
 */
final class Cursor
{
    /**
     * @param  array<string, string>  $positions  channel → last id seen
     */
    public function __construct(
        public readonly ?string $fallback = null,
        public readonly array $positions = [],
    ) {}

    public static function fromLastEventId(string $id): self
    {
        return new self($id);
    }

    public static function start(): self
    {
        return new self(null);
    }

    public function for(string $channel): ?string
    {
        return $this->positions[$channel] ?? $this->fallback;
    }

    public function advance(string $channel, string $id): self
    {
        $positions = $this->positions;
        $positions[$channel] = $id;

        $fallback = $this->fallback === null || self::compare($id, $this->fallback) > 0 ? $id : $this->fallback;

        return new self($fallback, $positions);
    }

    /**
     * Compare two ids: numeric when both are integers, Redis "ms-seq" when
     * both look like it, else string comparison.
     */
    public static function compare(string $a, string $b): int
    {
        if (ctype_digit($a) && ctype_digit($b)) {
            return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
        }

        if (preg_match('/^\d+-\d+$/', $a) && preg_match('/^\d+-\d+$/', $b)) {
            [$am, $as] = array_map('intval', explode('-', $a));
            [$bm, $bs] = array_map('intval', explode('-', $b));

            return $am <=> $bm ?: $as <=> $bs;
        }

        return strcmp($a, $b);
    }
}
