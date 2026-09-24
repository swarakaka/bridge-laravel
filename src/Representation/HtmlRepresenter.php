<?php

declare(strict_types=1);

namespace Bridge\Representation;

use Bridge\Errors\ErrorEnvelope;
use Bridge\Http\Responses\Redirect;
use Bridge\Negotiation\Negotiation;
use Bridge\Page\PageDocument;
use Bridge\Ssr\SsrGateway;
use Bridge\Support\Headers;
use Bridge\Support\Shell;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * text/html: the application shell, with the page object embedded by default
 * (spec/page.md §2 and §7).
 */
final class HtmlRepresenter implements Representer
{
    public const VIEW_PAGE_VARIABLE = 'bridgePage';

    public function __construct(
        private readonly ViewFactory $views,
        private readonly Repository $config,
        private readonly SsrGateway $ssr,
    ) {}

    public function represent(PageDocument $document, RenderOptions $options, Negotiation $negotiation, Request $request): Response
    {
        $embed = $options->embed ?? (bool) $this->config->get('bridge.shell.embed', true);
        $view = $options->shellView ?? (string) $this->config->get('bridge.shell.view', 'bridge::app');

        if (! $this->views->exists($view)) {
            throw new RuntimeException("Bridge shell view [{$view}] does not exist. Publish it with `php artisan bridge:install` or set bridge.shell.view.");
        }

        $page = $document->toArray();
        $rendered = (bool) $this->config->get('bridge.ssr.enabled', false) && $embed ? $this->ssr->render($page) : null;

        $data = [
            self::VIEW_PAGE_VARIABLE => $embed ? $page : null,
            'bridgeBuild' => $document->build,
            'bridgeProtocol' => $document->protocol,
            'bridgeSsrHead' => $rendered === null ? [] : $rendered->head,
            'bridgeSsrBody' => $rendered === null ? null : $rendered->body,
        ];

        // The <x-bridge::app /> and <x-bridge::head /> components read the same data from the request.
        Shell::remember($request, $embed ? $page : null, $document->protocol, $document->build, $rendered === null ? [] : $rendered->head, $rendered === null ? null : $rendered->body);

        $response = new HttpResponse($this->views->make($view, $data)->render(), $options->status);
        $response->headers->set('Content-Type', 'text/html; charset=utf-8');
        $response->headers->set('Vary', 'Accept');

        if ($options->cache !== null) {
            $options->cache->apply($response, $request);
        } elseif (! $embed) {
            // A static shell is CDN-safe for guests; CacheOptions keeps it private for signed-in users (spec/page.md §7).
            (new CacheOptions(maxAge: 300, public: true))->apply($response, $request);
        } elseif ($request->user() !== null) {
            $response->headers->set('Cache-Control', 'private, no-store');
        } else {
            $response->headers->set('Cache-Control', 'private, no-cache');
        }

        return $response;
    }

    public function representRedirect(Redirect $redirect, Negotiation $negotiation, Request $request): Response
    {
        return new RedirectResponse($redirect->url, 302);
    }

    public function representError(ErrorEnvelope $error, Negotiation $negotiation, Request $request): Response
    {
        // HTML errors are Laravel's business; this path is only reached when
        // an application explicitly asks Bridge to render an envelope as HTML.
        throw new HttpException($error->status, $error->message, null, $error->headers);
    }

    /**
     * JSON-encode the page for a <script type="application/json"> element.
     *
     * @param  array<string, mixed>  $page
     */
    public static function encodeEmbedded(array $page): string
    {
        return json_encode(
            $page,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    public static function embeddedElementId(): string
    {
        return Headers::EMBEDDED_PAGE_ID;
    }
}
