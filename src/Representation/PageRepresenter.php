<?php

declare(strict_types=1);

namespace Bridge\Representation;

use Bridge\Errors\ErrorEnvelope;
use Bridge\Http\Responses\Redirect;
use Bridge\Negotiation\Negotiation;
use Bridge\Page\PageDocument;
use Bridge\Support\Headers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * application/vnd.bridge+json (spec/page.md).
 */
final class PageRepresenter implements Representer
{
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public function represent(PageDocument $document, RenderOptions $options, Negotiation $negotiation, Request $request): Response
    {
        $response = new JsonResponse($document->toArray(), $options->status, [], self::JSON_FLAGS);
        $this->decorate($response, $negotiation);

        if ($options->cache !== null) {
            $options->cache->apply($response, $request);
        }

        return $response;
    }

    public function representRedirect(Redirect $redirect, Negotiation $negotiation, Request $request): Response
    {
        if ($redirect->external || ! self::isSameOrigin($redirect->url, $request)) {
            return self::externalRedirect($redirect->url);
        }

        $response = new RedirectResponse($redirect->url, 303);
        $response->headers->set('Vary', Headers::PAGE_VARY);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function representError(ErrorEnvelope $error, Negotiation $negotiation, Request $request): Response
    {
        $response = new JsonResponse([
            'protocol' => $negotiation->protocolVersion,
            'type' => 'error',
            'error' => $error->toArray(),
        ], $error->status, $error->headers, self::JSON_FLAGS);
        $this->decorate($response, $negotiation);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public static function externalRedirect(string $url): Response
    {
        $response = new Response('', 409);
        $response->headers->set(Headers::LOCATION, $url);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public static function isSameOrigin(string $url, Request $request): bool
    {
        // Browsers (WHATWG URL) read "\" as "/" and drop tabs and newlines, so "/\evil.com"
        // and "/\t/evil.com" are protocol-relative. Anything ambiguous counts as external.
        if (str_contains($url, '\\') || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//');
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return false;
        }

        if (! isset($parts['host'])) {
            return ! isset($parts['scheme']);
        }

        $host = strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
        $requestHost = strtolower($request->getHttpHost());
        $scheme = $parts['scheme'] ?? $request->getScheme();

        return $host === $requestHost && strtolower($scheme) === $request->getScheme();
    }

    private function decorate(JsonResponse $response, Negotiation $negotiation): void
    {
        $response->headers->set('Content-Type', $negotiation->contentType());
        $response->headers->set('Vary', Headers::PAGE_VARY);
        $response->headers->set('Cache-Control', 'private, no-cache');
    }
}
