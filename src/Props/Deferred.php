<?php

declare(strict_types=1);

namespace Bridge\Props;

use Closure;

/**
 * Excluded from the initial page response and listed under `deferred[group]`
 * so the client fetches it right after render. Resolved inline in JSON mode.
 */
final class Deferred extends PropHint
{
    public function __construct(Closure $value, public readonly string $group = 'default')
    {
        parent::__construct($value);
    }
}
