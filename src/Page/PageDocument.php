<?php

declare(strict_types=1);

namespace Bridge\Page;

/**
 * A fully resolved, JSON-ready page object (spec/page.md §1).
 */
final class PageDocument
{
    /**
     * @param  array<string, mixed>  $props
     * @param  array<string, list<string>>  $deferred
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly int $protocol,
        public readonly string $component,
        public readonly string $url,
        public readonly array $props,
        public readonly ?string $build,
        public readonly array $deferred = [],
        public readonly array $meta = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $document = [
            'protocol' => $this->protocol,
            'type' => 'page',
            'component' => $this->component,
            'url' => $this->url,
            'props' => $this->props === [] ? new \stdClass : $this->props,
            'build' => $this->build,
        ];

        if ($this->deferred !== []) {
            $document['deferred'] = $this->deferred;
        }

        if ($this->meta !== []) {
            $document['meta'] = $this->meta;
        }

        return $document;
    }
}
