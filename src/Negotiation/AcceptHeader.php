<?php

declare(strict_types=1);

namespace Bridge\Negotiation;

/**
 * RFC 9110 §12.5.1 Accept header parser. Malformed ranges are dropped; an
 * empty result is treated as "*\/*" by the negotiator.
 */
final class AcceptHeader
{
    /**
     * @param  list<MediaRange>  $ranges
     */
    private function __construct(public readonly array $ranges) {}

    public static function parse(?string $header): self
    {
        $header = trim((string) $header);

        if ($header === '') {
            return new self([self::wildcard()]);
        }

        $ranges = [];
        $position = 0;

        foreach (self::splitOnUnquotedCommas($header) as $item) {
            $range = self::parseRange(trim($item), $position);

            if ($range !== null) {
                $ranges[] = $range;
                $position++;
            }
        }

        return new self($ranges === [] ? [self::wildcard()] : $ranges);
    }

    public static function wildcard(): MediaRange
    {
        return new MediaRange('*', '*', 1.0, [], 0);
    }

    /**
     * Best matching range for the media type, by specificity then position.
     */
    public function bestMatch(string $mediaType): ?MediaRange
    {
        $best = null;

        foreach ($this->ranges as $range) {
            if (! $range->matches($mediaType)) {
                continue;
            }

            if ($best === null || $range->specificity() > $best->specificity()) {
                $best = $range;
            }
        }

        return $best;
    }

    /**
     * @return list<string>
     */
    private static function splitOnUnquotedCommas(string $header): array
    {
        $parts = [];
        $current = '';
        $inQuotes = false;
        $length = strlen($header);

        for ($i = 0; $i < $length; $i++) {
            $char = $header[$i];

            if ($char === '"' && ($i === 0 || $header[$i - 1] !== '\\')) {
                $inQuotes = ! $inQuotes;
            }

            if ($char === ',' && ! $inQuotes) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return $parts;
    }

    private static function parseRange(string $item, int $position): ?MediaRange
    {
        if ($item === '') {
            return null;
        }

        $segments = array_map('trim', explode(';', $item));
        $mediaType = strtolower((string) array_shift($segments));

        if (! preg_match('#^([a-z0-9!\#$&^_.+-]+|\*)/([a-z0-9!\#$&^_.+-]+|\*)$#', $mediaType, $m)) {
            return null;
        }

        [, $type, $subtype] = $m;

        if ($type === '*' && $subtype !== '*') {
            return null;
        }

        $quality = 1.0;
        $parameters = [];

        foreach ($segments as $segment) {
            if ($segment === '' || ! str_contains($segment, '=')) {
                continue;
            }

            [$name, $value] = array_map('trim', explode('=', $segment, 2));
            $name = strtolower($name);
            $value = trim($value, '"');

            if ($name === 'q') {
                if (! is_numeric($value)) {
                    return null;
                }

                $quality = max(0.0, min(1.0, (float) $value));

                continue;
            }

            $parameters[$name] = $value;
        }

        return new MediaRange($type, $subtype, $quality, $parameters, $position);
    }
}
