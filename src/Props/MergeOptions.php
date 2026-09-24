<?php

declare(strict_types=1);

namespace Bridge\Props;

use InvalidArgumentException;

/**
 * How a merge prop combines with the current value on opted-in partial
 * reloads (spec/page.md §3).
 */
final class MergeOptions
{
    public const APPEND = 'append';

    public const PREPEND = 'prepend';

    public const DEEP = 'deep';

    /**
     * @param  self::APPEND|self::PREPEND|self::DEEP  $mode
     * @param  list<string>  $matchOn  match paths: the last segment is the item key
     */
    public function __construct(
        public readonly string $mode = self::APPEND,
        public readonly array $matchOn = [],
    ) {
        foreach ($matchOn as $path) {
            if ($path === '' || preg_match('/[,\s]/', $path) === 1) {
                throw new InvalidArgumentException("Match path [{$path}] must be non-empty and contain no commas or whitespace.");
            }
        }
    }
}
