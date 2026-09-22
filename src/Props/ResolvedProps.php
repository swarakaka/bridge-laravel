<?php

declare(strict_types=1);

namespace Bridge\Props;

/**
 * Output of the resolver: serialized props plus the deferred group map.
 */
final class ResolvedProps
{
    /**
     * @param  array<string, mixed>  $props
     * @param  array<string, list<string>>  $deferred
     */
    public function __construct(
        public readonly array $props,
        public readonly array $deferred = [],
        public readonly bool $partial = false,
    ) {}
}
