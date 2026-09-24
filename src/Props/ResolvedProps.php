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
     * @param  list<string>  $prepend  keys the client prepends on partial reloads
     * @param  list<string>  $deepMerge  keys the client deep-merges on partial reloads
     * @param  array<string, list<string>>  $matchOn  match paths per merge key
     * @param  array<string, array{key: string, expiresAt: int|null}>  $once  once props considered (spec/page.md §11)
     */
    public function __construct(
        public readonly array $props,
        public readonly array $deferred = [],
        public readonly bool $partial = false,
        public readonly array $merge = [],
        public readonly array $once = [],
        public readonly array $prepend = [],
        public readonly array $deepMerge = [],
        public readonly array $matchOn = [],
    ) {}

    /**
     * The merge members of `meta` (spec/page.md §3), empty ones left out.
     *
     * @return array<string, mixed>
     */
    public function mergeMeta(): array
    {
        return array_filter([
            'merge' => $this->merge,
            'prepend' => $this->prepend,
            'deepMerge' => $this->deepMerge,
            'matchOn' => $this->matchOn,
        ], static fn (array $member): bool => $member !== []);
    }
}
