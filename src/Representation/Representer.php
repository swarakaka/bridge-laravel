<?php

declare(strict_types=1);

namespace Bridge\Representation;

use Bridge\Errors\ErrorEnvelope;
use Bridge\Http\Responses\Redirect;
use Bridge\Negotiation\Negotiation;
use Bridge\Page\PageDocument;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Transport layer: turns representation objects into HTTP responses for one mode.
 */
interface Representer
{
    public function represent(PageDocument $document, RenderOptions $options, Negotiation $negotiation, Request $request): Response;

    public function representRedirect(Redirect $redirect, Negotiation $negotiation, Request $request): Response;

    public function representError(ErrorEnvelope $error, Negotiation $negotiation, Request $request): Response;
}
