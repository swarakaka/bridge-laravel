<?php

declare(strict_types=1);

namespace Bridge\Props;

use Closure;
use Illuminate\Contracts\Container\Container;

/**
 * A delivery hint wrapping a prop value. See spec/page.md §4.
 */
abstract class PropHint
{
    public function __construct(protected readonly mixed $value) {}

    public function resolve(Container $container): mixed
    {
        return $this->value instanceof Closure
            ? $container->call($this->value)
            : $this->value;
    }
}
