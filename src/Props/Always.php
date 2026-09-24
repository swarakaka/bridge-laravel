<?php

declare(strict_types=1);

namespace Bridge\Props;

use LogicException;

/**
 * Included in every response, even partial ones that do not name it. Takes
 * no modifier: it is sent every time by definition.
 */
final class Always extends PropHint
{
    protected function refuse(string $modifier): void
    {
        throw new LogicException("Bridge::always() props cannot use {$modifier}(): they are sent on every response.");
    }
}
