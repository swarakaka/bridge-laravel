<?php

declare(strict_types=1);

namespace Bridge\Http\Responses;

use Bridge\BridgeManager;
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

    private ?string $jsonRoot = null;

    private ?bool $encryptHistory = null;

    public function __construct(
        private Page $page,
        private readonly BridgeManager $bridge,
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

    /**
     * JSON mode only: make one prop the `data` root and move the others to `meta`.
     */
    public function jsonRoot(string $key): self
    {
        $this->jsonRoot = $key;

        return $this;
    }

    /**
     * Store this page encrypted in the client's history (spec/page.md §10),
     * whatever the request or `bridge.history.encrypt` say.
     */
    public function encryptHistory(bool $encrypt = true): self
    {
        $this->encryptHistory = $encrypt;

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
        $options = new RenderOptions($this->embed, $this->cache, $this->shellView, $this->status, $this->jsonRoot);

        return $this->representers->for($negotiation->mode)->represent($document, $options, $negotiation, $request);
    }

    public function document(Negotiation $negotiation, Request $request): PageDocument
    {
        $resolved = $this->resolver->resolve($this->page, $this->bridge->shared(), $negotiation->mode, $request);
        $meta = $this->page->meta;

        if ($resolved->merge !== []) {
            $meta['merge'] = $resolved->merge;
        }

        if ($resolved->once !== []) {
            $meta['once'] = $resolved->once;
        }

        // History members only concern clients that keep pages in browser history.
        if (in_array($negotiation->mode, [Mode::Page, Mode::Html], true)) {
            $meta = array_merge($meta, $this->bridge->historyMeta($request, $this->encryptHistory));
        }

        return new PageDocument(
            protocol: $negotiation->protocolVersion,
            component: $this->page->component,
            url: self::urlFor($request),
            props: $resolved->props,
            build: $this->bridge->version(),
            deferred: $resolved->deferred,
            meta: $meta,
        );
    }

    public static function urlFor(Request $request): string
    {
        $uri = $request->getRequestUri();

        return str_starts_with($uri, '/') ? $uri : '/'.$uri;
    }
}
