<?php

declare(strict_types=1);

namespace Bridge\Http\Middleware;

use Bridge\BridgeManager;
use Bridge\Negotiation\ContentNegotiator;
use Bridge\Negotiation\Mode;
use Bridge\Negotiation\Negotiation;
use Bridge\Negotiation\NotAcceptableException;
use Bridge\Negotiation\UnsupportedProtocolVersionException;
use Bridge\Representation\PageRepresenter;
use Bridge\Support\Headers;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Negotiates once per request, enforces build conflicts (409), converts
 * redirects for page mode, and adds ETag / 304 handling (PLAN §9.3).
 *
 * Applications may extend it (`php artisan bridge:middleware`) to share
 * props per request; auto-registration then steps aside.
 */
class HandleBridgeRequests
{
    /**
     * What downstream code sees as Accept during a page visit, so Laravel's
     * wantsJson()/expectsJson() take their browser branch (PLAN §5.4).
     */
    public const BROWSER_ACCEPT = 'text/html, application/xhtml+xml';

    public function __construct(
        private readonly ContentNegotiator $negotiator,
        private readonly BridgeManager $bridge,
        private readonly Repository $config,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $negotiation = $this->negotiator->negotiate($request);
        } catch (UnsupportedProtocolVersionException $e) {
            // A Bridge client speaking another protocol version: refuse before the controller runs.
            return new JsonResponse($e->toBody(), 406);
        } catch (NotAcceptableException) {
            // This middleware runs for the whole `web` group, and not every route there
            // renders through Bridge (downloads, feeds). Let the route answer; a Bridge
            // response for this request still fails with 406 when it negotiates.
            return $next($request);
        }

        $request->attributes->set(Negotiation::REQUEST_ATTRIBUTE, $negotiation);

        $shared = $this->share($request);

        if ($shared !== []) {
            $this->bridge->share($shared);
        }

        if ($negotiation->mode === Mode::Page && $request->isMethod('GET') && $this->buildIsStale($request)) {
            return PageRepresenter::externalRedirect($request->fullUrl());
        }

        $response = $negotiation->mode === Mode::Page
            ? $this->asBrowserVisit($request, $next)
            : $next($request);

        if ($negotiation->mode === Mode::Page && $response instanceof RedirectResponse) {
            $response = $this->convertRedirect($response, $request);
        }

        if (in_array($negotiation->mode, [Mode::Page, Mode::Html], true)) {
            $this->bridge->carryClearHistory($request, $response);
        }

        if ($negotiation->mode === Mode::Page) {
            $this->addVary($response, Headers::PAGE_VARY);
        } elseif ($negotiation->mode === Mode::Json) {
            $this->addVary($response, Headers::VARY);
        } elseif ($negotiation->mode === Mode::Html) {
            $this->addVary($response, 'Accept');
        }

        if ($this->shouldEtag($request, $response, $negotiation)) {
            $content = $response->getContent();

            if (is_string($content) && $content !== '') {
                $response->setEtag(hash('xxh128', $content), true);
                $response->isNotModified($request);
            }
        }

        return $response;
    }

    /**
     * Props shared with this request, for subclasses. Values may be closures,
     * resolved only when a response includes them. Like any share made after
     * boot, they are dropped once the request is handled.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [];
    }

    /**
     * A page visit is a browser navigation: code that branches on
     * wantsJson()/expectsJson() (Fortify, `verified`, `password.confirm`)
     * must redirect, not answer as an API. Bridge itself reads the stored
     * negotiation, never the header. The original value stays in a request
     * attribute and is restored for code that runs after the response.
     */
    private function asBrowserVisit(Request $request, Closure $next): Response
    {
        $original = $request->headers->get('Accept');
        $request->attributes->set(Negotiation::ORIGINAL_ACCEPT_ATTRIBUTE, $original);

        $this->setAccept($request, self::BROWSER_ACCEPT);

        try {
            return $next($request);
        } finally {
            $this->setAccept($request, $original);
        }
    }

    private function setAccept(Request $request, ?string $accept): void
    {
        $request->headers->set('Accept', $accept);
        $request->server->set('HTTP_ACCEPT', $accept);

        // Symfony caches the parsed header on first use, possibly by an earlier middleware.
        (fn () => $this->acceptableContentTypes = null)->call($request);
    }

    private function buildIsStale(Request $request): bool
    {
        $clientBuild = $request->headers->get(Headers::BUILD);

        if ($clientBuild === null || $clientBuild === '') {
            return false;
        }

        $serverBuild = $this->bridge->version();

        return $serverBuild !== null && $serverBuild !== $clientBuild;
    }

    private function convertRedirect(RedirectResponse $response, Request $request): Response
    {
        $target = $response->getTargetUrl();

        if (! PageRepresenter::isSameOrigin($target, $request)) {
            return PageRepresenter::externalRedirect($target);
        }

        $response->setStatusCode(303);

        return $response;
    }

    private function addVary(Response $response, string $vary): void
    {
        $existing = $response->headers->get('Vary');

        if ($existing === null || $existing === '') {
            $response->headers->set('Vary', $vary);

            return;
        }

        $merged = array_unique(array_merge(
            array_map('trim', explode(',', $existing)),
            array_map('trim', explode(',', $vary)),
        ));

        $response->headers->set('Vary', implode(', ', $merged));
    }

    private function shouldEtag(Request $request, Response $response, Negotiation $negotiation): bool
    {
        if (! (bool) $this->config->get('bridge.cache.etag', true)) {
            return false;
        }

        if (! in_array($negotiation->mode, [Mode::Page, Mode::Json], true)) {
            return false;
        }

        if (! $request->isMethodCacheable() || $response->getStatusCode() !== 200 || $response->headers->has('ETag')) {
            return false;
        }

        return ! str_contains((string) $response->headers->get('Cache-Control'), 'no-store');
    }
}
