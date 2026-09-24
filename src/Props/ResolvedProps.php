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
     * @param  list<string>  $merge  keys the client appends on partial reloads
     * @param  array<string, array{key: string, expiresAt: int|null}>  $once  once props considered (spec/page.md §11)
     */
    public function __construct(
        public readonly array $props,
        public readonly array $deferred = [],
        public readonly bool $partial = false,
        public readonly array $merge = [],
        public readonly array $once = [],
    ) {}
}
