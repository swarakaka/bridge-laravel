<?php

declare(strict_types=1);

namespace Bridge\Http\Responses;

use Bridge\Bridge;
use Bridge\Negotiation\Mode;
use Bridge\Negotiation\Negotiation;
use Bridge\Negotiation\NotAcceptableException;
use Bridge\Page\Page;
use Bridge\Page\PageDocument;
use Bridge\Props\PropResolver;
use Bridge\Representation\CacheOptions;
use Bridge\Representation\RenderOptions;
use Bridge\Representation\RepresenterRegistry;
use DateTimeInterface;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Returned by Bridge::render(). Negotiation decides the representation at
 * toResponse() time; controllers never branch on mode.
 */
final class PageResponse implements Responsable
{
    private ?bool $embed = null;

    private ?CacheOptions $cache = null;

    private ?string $shellView = null;

    private int $status = 200;

    public function __construct(
        private Page $page,
        private readonly Bridge $bridge,
        private readonly PropResolver $resolver,
        private readonly RepresenterRegistry $representers,
    ) {}

    /**
     * @param  array<string, mixed>  $props
     */
    public function with(array $props): self
    {
        $this->page = $this->page->withProps($props);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function withMeta(array $meta): self
    {
        $this->page = $this->page->withMeta($meta);

        return $this;
    }

    public function embed(bool $embed = true): self
    {
        $this->embed = $embed;

        return $this;
    }

    public function shell(string $view): self
    {
        $this->shellView = $view;

        return $this;
    }

    public function status(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function cache(?int $maxAge = null, bool $public = false, bool $force = false): self
    {
        $this->cache = new CacheOptions($maxAge, $public, $force, $this->cache?->lastModified);

        return $this;
    }

    public function lastModified(DateTimeInterface $lastModified): self
    {
        $current = $this->cache;

        $this->cache = $current === null
            ? new CacheOptions(null, false, false, $lastModified)
            : new CacheOptions($current->maxAge, $current->public, $current->force, $lastModified);

        return $this;
    }

    public function page(): Page
    {
        return $this->page;
    }

    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        $negotiation = Negotiation::for($request);

        if (! $negotiation->mode->isRendering()) {
            throw new NotAcceptableException(array_merge(
                Mode::Page->mediaTypes(),
                Mode::Json->mediaTypes(),
                Mode::Html->mediaTypes(),
            ));
        }

        $document = $this->document($negotiation, $request);
        $options = new RenderOptions($this->embed, $this->cache, $this->shellView, $this->status);

        return $this->representers->for($negotiation->mode)->represent($document, $options, $negotiation, $request);
    }

    public function document(Negotiation $negotiation, Request $request): PageDocument
    {
        $resolved = $this->resolver->resolve($this->page, $this->bridge->shared(), $negotiation->mode, $request);

        return new PageDocument(
            protocol: $negotiation->protocolVersion,
            component: $this->page->component,
            url: self::urlFor($request),
            props: $resolved->props,
            build: $this->bridge->version(),
            deferred: $resolved->deferred,
            meta: $this->page->meta,
        );
    }

    public static function urlFor(Request $request): string
    {
        $uri = $request->getRequestUri();

        return str_starts_with($uri, '/') ? $uri : '/'.$uri;
    }
}
