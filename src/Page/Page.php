<?php

declare(strict_types=1);

namespace Bridge\Page;

/**
 * The representation of a page before resolution: a component name and an
 * unresolved prop bag. Immutable; `with*` methods return copies.
 */
final class Page
{
    /**
     * @param  array<string, mixed>  $props
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $component,
        public readonly array $props = [],
        public readonly array $meta = [],
    ) {}

    /**
     * @param  array<string, mixed>  $props
     */
    public function withProps(array $props): self
    {
        return new self($this->component, array_merge($this->props, $props), $this->meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function withMeta(array $meta): self
    {
        return new self($this->component, $this->props, array_merge($this->meta, $meta));
    }
}
