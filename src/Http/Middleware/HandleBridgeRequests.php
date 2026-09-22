<?php

declare(strict_types=1);

namespace Bridge\Http\Middleware;

use Bridge\Bridge;
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
 */
final class HandleBridgeRequests
{
    public function __construct(
        private readonly ContentNegotiator $negotiator,
        private readonly Bridge $bridge,
        private readonly Repository $config,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $negotiation = $this->negotiator->negotiate($request);
        } catch (NotAcceptableException|UnsupportedProtocolVersionException $e) {
            return new JsonResponse($e->toBody(), 406);
        }

        $request->attributes->set(Negotiation::REQUEST_ATTRIBUTE, $negotiation);

        if ($negotiation->mode === Mode::Page && $request->isMethod('GET') && $this->buildIsStale($request)) {
            return PageRepresenter::externalRedirect($request->fullUrl());
        }

        $response = $next($request);

        if ($negotiation->mode === Mode::Page && $response instanceof RedirectResponse) {
            $response = $this->convertRedirect($response, $request);
        }

        if (in_array($negotiation->mode, [Mode::Page, Mode::Json], true)) {
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
