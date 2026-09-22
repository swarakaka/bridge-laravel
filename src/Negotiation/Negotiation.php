<?php

declare(strict_types=1);

namespace Bridge\Negotiation;

use Illuminate\Http\Request;

/**
 * The result of content negotiation, stored on the request.
 */
final class Negotiation
{
    public const REQUEST_ATTRIBUTE = 'bridge.negotiation';

    public function __construct(
        public readonly Mode $mode,
        public readonly int $protocolVersion,
        public readonly string $acceptedType,
        public readonly ?MediaRange $matchedRange,
    ) {}

    public function is(Mode $mode): bool
    {
        return $this->mode === $mode;
    }

    /**
     * True when the request only matched through a wildcard range; used to
     * honour configured default modes.
     */
    public function viaWildcard(): bool
    {
        return $this->matchedRange !== null && $this->matchedRange->specificity() === 0;
    }

    /**
     * The Content-Type a response in this negotiation's mode should carry.
     */
    public function contentType(): string
    {
        return match ($this->mode) {
            Mode::Page => Mode::PAGE_MEDIA_TYPE.'; v='.$this->protocolVersion,
            Mode::Json => 'application/json',
            Mode::Stream => 'text/event-stream; charset=utf-8',
            Mode::Html => 'text/html; charset=utf-8',
        };
    }

    /**
     * Resolve (and cache) the negotiation for a request, running the
     * negotiator lazily when the middleware did not.
     */
    public static function for(Request $request): self
    {
        $existing = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        if ($existing instanceof self) {
            return $existing;
        }

        $negotiation = app(ContentNegotiator::class)->negotiate($request);
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $negotiation);

        return $negotiation;
    }
}
