<?php

declare(strict_types=1);

namespace Bridge\Props;

use InvalidArgumentException;

/**
 * Included normally, but listed in a merge member of `meta` (spec/page.md §3)
 * so clients combine it with the current value on opted-in partial reloads:
 * append (default), prepend, or deep. Match paths replace items already shown
 * instead of adding them twice. Pass a Closure when the value is expensive.
 */
final class Merge extends PropHint
{
    public const APPEND = 'append';

    public const PREPEND = 'prepend';

    public const DEEP = 'deep';

    /**
     * @param  self::APPEND|self::PREPEND|self::DEEP  $mode
     * @param  list<string>  $matchOn
     */
    public function __construct(
        mixed $value,
        public readonly string $mode = self::APPEND,
        public readonly array $matchOn = [],
    ) {
        if ($value instanceof PropHint) {
            throw new InvalidArgumentException('Bridge::merge() cannot wrap another prop hint (lazy, defer, always, merge or once).');
        }

        foreach ($matchOn as $path) {
            if ($path === '' || preg_match('/[,\s]/', $path) === 1) {
                throw new InvalidArgumentException("Match path [{$path}] must be non-empty and contain no commas or whitespace.");
            }
        }

        parent::__construct($value);
    }

    /** Incoming items go after the current ones (the default). Returns a copy. */
    public function append(): self
    {
        return new self($this->value, self::APPEND, $this->matchOn);
    }

    /** Incoming items go before the current ones (feeds, chat history). Returns a copy. */
    public function prepend(): self
    {
        return new self($this->value, self::PREPEND, $this->matchOn);
    }

    /** Objects merge key by key at every depth. Returns a copy. */
    public function deep(): self
    {
        return new self($this->value, self::DEEP, $this->matchOn);
    }

    /**
     * Items whose key matches an item already shown replace it instead of
     * being added again. The last path segment is the item key: `id` for a
     * list, `data.id` for a paginator. No paths clears matching. Returns a copy.
     */
    public function matchOn(string ...$paths): self
    {
        return new self($this->value, $this->mode, array_values($paths));
    }
}
