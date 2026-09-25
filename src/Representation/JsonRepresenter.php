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
use Symfony\Component\HttpFoundation\Response;

/**
 * application/json (spec/json.md): { data, meta? } with Laravel-native shapes.
 */
final class JsonRepresenter implements Representer
{
    public function represent(PageDocument $document, RenderOptions $options, Negotiation $negotiation, Request $request): Response
    {
        $props = $document->props;
        // Merge hints only concern page clients (spec/page.md §3).
        $meta = array_diff_key($document->meta, ['merge' => true, 'prepend' => true, 'deepMerge' => true, 'matchOn' => true, 'scroll' => true, 'watch' => true]);

        if ($options->jsonRoot !== null && array_key_exists($options->jsonRoot, $props)) {
            $root = $props[$options->jsonRoot];
            unset($props[$options->jsonRoot]);
            $meta = array_merge($meta, $props);
            $body = ['data' => $root];
        } else {
            $body = ['data' => $props === [] ? new \stdClass : $props];
        }

        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        $response = $this->json($body, $options->status);

        if ($options->cache !== null) {
            $options->cache->apply($response, $request);
        }

        return $response;
    }

    public function representRedirect(Redirect $redirect, Negotiation $negotiation, Request $request): Response
    {
        $meta = ['location' => $redirect->url];

        if ($redirect->flash !== null && $redirect->flash !== []) {
            $meta['flash'] = $redirect->flash;
        }

        $response = $this->json([
            'data' => $redirect->data === [] ? null : $redirect->data,
            'meta' => $meta,
        ], $redirect->jsonStatus ?? 200);
        $response->headers->set('Location', $redirect->url);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function representError(ErrorEnvelope $error, Negotiation $negotiation, Request $request): Response
    {
        $response = $this->json($error->toJsonBody(), $error->status);

        foreach ($error->headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function json(array $body, int $status): JsonResponse
    {
        $response = new JsonResponse($body, $status, [], PageRepresenter::JSON_FLAGS);
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Vary', Headers::VARY);
        $response->headers->set('Cache-Control', 'private, no-cache');

        return $response;
    }
}
