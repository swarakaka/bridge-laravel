<?php

declare(strict_types=1);

namespace Bridge\Props;

use InvalidArgumentException;

/**
 * A plain prop with the merge modifier (spec/page.md §3): `Bridge::merge()`.
 * Clients combine it with the current value on opted-in partial reloads:
 * append (default), prepend, or deep; match paths replace items already
 * shown. Pass a Closure when the value is expensive.
 */
final class Merge extends PropHint
{
    public const APPEND = MergeOptions::APPEND;

    public const PREPEND = MergeOptions::PREPEND;

    public const DEEP = MergeOptions::DEEP;

    /**
     * @param  self::APPEND|self::PREPEND|self::DEEP  $mode
     * @param  list<string>  $matchOn
     */
    public function __construct(mixed $value, string $mode = self::APPEND, array $matchOn = [])
    {
        if ($value instanceof PropHint) {
            throw new InvalidArgumentException('Bridge::merge() cannot wrap another prop hint; chain ->merge() on it instead.');
        }

        parent::__construct($value);
        $this->initMerge(new MergeOptions($mode, $matchOn));
    }
}
