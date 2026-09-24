<?php

declare(strict_types=1);

namespace Bridge\Stream\Bus;

/**
 * Position in the bus. `fallback` applies to every channel without an explicit
 * position; drivers with a global order (database, sync) use only the fallback.
 *
 * `floor` is where the connection started: nothing at or before it belongs to
 * the connection. `recent` lists ids delivered lately, so a driver whose ids
 * can become visible out of order (database) can find ones it skipped.
 */
final class Cursor
{
    public const RECENT_LIMIT = 1000;

    public readonly ?string $floor;

    /**
     * @param  array<string, string>  $positions  channel → last id seen
     * @param  list<string>  $recent
     */
    public function __construct(
        public readonly ?string $fallback = null,
        public readonly array $positions = [],
        ?string $floor = null,
        public readonly array $recent = [],
    ) {
        $this->floor = $floor ?? $fallback;
    }

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

    /** Whether $id sorts before the furthest id delivered so far. */
    public function isBehind(string $id): bool
    {
        return $this->fallback !== null && self::compare($id, $this->fallback) < 0;
    }

    public function advance(string $channel, string $id): self
    {
        $positions = $this->positions;
        $current = $positions[$channel] ?? null;

        if ($current === null || self::compare($id, $current) > 0) {
            $positions[$channel] = $id;
        }

        $fallback = $this->fallback === null || self::compare($id, $this->fallback) > 0 ? $id : $this->fallback;

        $recent = $this->recent;
        $recent[] = $id;

        if (count($recent) > self::RECENT_LIMIT) {
            $recent = array_slice($recent, -self::RECENT_LIMIT);
        }

        return new self($fallback, $positions, $this->floor, $recent);
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
