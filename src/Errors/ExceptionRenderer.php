<?php

declare(strict_types=1);

namespace Bridge\Errors;

use Bridge\Negotiation\Mode;
use Bridge\Negotiation\Negotiation;
use Bridge\Negotiation\NotAcceptableException;
use Bridge\Negotiation\UnsupportedProtocolVersionException;
use Bridge\Representation\RepresenterRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Registered with Laravel's exception handler via renderable(). Takes over
 * rendering for page and JSON mode; returns null so Laravel handles HTML.
 */
final class ExceptionRenderer
{
    public function __construct(
        private readonly ErrorMapper $mapper,
        private readonly RepresenterRegistry $representers,
    ) {}

    public function __invoke(Throwable $e, Request $request): ?Response
    {
        if ($e instanceof NotAcceptableException || $e instanceof UnsupportedProtocolVersionException) {
            return new JsonResponse($e->toBody(), 406);
        }

        try {
            $negotiation = Negotiation::for($request);
        } catch (UnsupportedProtocolVersionException $negotiationError) {
            return new JsonResponse($negotiationError->toBody(), 406);
        } catch (NotAcceptableException) {
            // Not a Bridge representation (e.g. a CSV route that failed): Laravel's handler decides.
            return null;
        }

        if (! in_array($negotiation->mode, [Mode::Page, Mode::Json, Mode::Stream], true)) {
            return null;
        }

        $envelope = $this->mapper->map($e, $request);

        // Errors before a stream is established are plain JSON (spec/errors.md §3.4).
        $mode = $negotiation->mode === Mode::Stream ? Mode::Json : $negotiation->mode;

        return $this->representers->for($mode)->representError($envelope, $negotiation, $request);
    }
}
