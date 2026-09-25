<?php

declare(strict_types=1);

namespace Bridge\Props;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A plain prop with the watch modifier (PLAN §20.6, spec/page.md §13):
 * `Bridge::watch($value, Customer::class)`. Clients reload it when a stream
 * reports a change to one of its sources. Pass a Closure when the value is
 * expensive.
 */
final class Watch extends PropHint
{
    public function __construct(mixed $value, Model|string ...$sources)
    {
        if ($value instanceof PropHint) {
            throw new InvalidArgumentException('Bridge::watch() cannot wrap another prop hint; chain ->watch() on it instead.');
        }

        if ($sources === []) {
            throw new InvalidArgumentException('Bridge::watch() needs at least one source: a model class, a model or a tag.');
        }

        parent::__construct($value);
        $this->initWatch(...array_values($sources));
    }
}
