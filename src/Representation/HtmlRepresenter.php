<?php

declare(strict_types=1);

namespace Bridge\Representation;

use Bridge\Errors\ErrorEnvelope;
use Bridge\Http\Responses\Redirect;
use Bridge\Negotiation\Negotiation;
use Bridge\Page\PageDocument;
use Bridge\Support\Headers;
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
    ) {}

    public function represent(PageDocument $document, RenderOptions $options, Negotiation $negotiation, Request $request): Response
    {
        $embed = $options->embed ?? (bool) $this->config->get('bridge.shell.embed', true);
        $view = $options->shellView ?? (string) $this->config->get('bridge.shell.view', 'bridge::app');

        if (! $this->views->exists($view)) {
            throw new RuntimeException("Bridge shell view [{$view}] does not exist. Publish it with `php artisan bridge:install` or set bridge.shell.view.");
        }

        $data = [
            self::VIEW_PAGE_VARIABLE => $embed ? $document->toArray() : null,
            'bridgeBuild' => $document->build,
            'bridgeProtocol' => $document->protocol,
        ];

        $response = new HttpResponse($this->views->make($view, $data)->render(), $options->status);
        $response->headers->set('Content-Type', 'text/html; charset=utf-8');
        $response->headers->set('Vary', 'Accept');

        if ($options->cache !== null) {
            $options->cache->apply($response, $request);
        } elseif (! $embed) {
            $response->headers->set('Cache-Control', 'public, max-age=300, must-revalidate');
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
