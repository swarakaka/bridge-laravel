<?php

declare(strict_types=1);

namespace Bridge\Props;

use Closure;

/**
 * Excluded from full page loads; included only when named in X-Bridge-Only.
 */
final class Lazy extends PropHint
{
    public function __construct(Closure $value)
    {
        parent::__construct($value);
    }
}
