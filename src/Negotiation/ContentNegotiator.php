<?php

declare(strict_types=1);

namespace Bridge\Negotiation;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * Implements packages/protocol/spec/negotiation.md §2–§3.
 */
final class ContentNegotiator
{
    public function __construct(
        private readonly int $maxProtocolVersion = 1,
        private readonly Mode $defaultMode = Mode::Html,
    ) {}

    public function negotiate(Request $request): Negotiation
    {
        $accept = AcceptHeader::parse($request->headers->get('Accept'));

        $winner = null;
        $winnerQuality = -1.0;
        $winnerRange = null;
        $winnerType = '';
        $allViaWildcard = true;

        foreach (Mode::inRankOrder() as $mode) {
            [$quality, $range, $type] = $this->bestQualityFor($accept, $mode);

            if ($range === null || $quality <= 0.0) {
                continue;
            }

            if ($range->specificity() > 0) {
                $allViaWildcard = false;
            }

            if ($quality > $winnerQuality) {
                $winner = $mode;
                $winnerQuality = $quality;
                $winnerRange = $range;
                $winnerType = $type;
            }
        }

        if ($winner === null || $winnerRange === null) {
            throw new NotAcceptableException($this->acceptableTypes());
        }

        if ($allViaWildcard) {
            $winner = $this->wildcardFallback($request, $accept);
            $winnerType = $winner->mediaTypes()[0];
        }

        $version = 1;

        if ($winner === Mode::Page) {
            $version = $this->protocolVersion($winnerRange);
        }

        return new Negotiation($winner, $version, $winnerType, $winnerRange);
    }

    /**
     * @return array{0: float, 1: ?MediaRange, 2: string}
     */
    private function bestQualityFor(AcceptHeader $accept, Mode $mode): array
    {
        $best = null;
        $bestType = '';

        foreach ($mode->mediaTypes() as $type) {
            $range = $accept->bestMatch($type);

            if ($range === null) {
                continue;
            }

            if ($best === null || $range->specificity() > $best->specificity()
                || ($range->specificity() === $best->specificity() && $range->quality > $best->quality)) {
                $best = $range;
                $bestType = $type;
            }
        }

        return [$best === null ? 0.0 : $best->quality, $best, $bestType];
    }

    private function protocolVersion(MediaRange $range): int
    {
        $raw = $range->parameter('v');

        if ($raw === null) {
            return 1;
        }

        if (! preg_match('/^[1-9]\d*$/', $raw) || (int) $raw > $this->maxProtocolVersion) {
            throw new UnsupportedProtocolVersionException(range(1, $this->maxProtocolVersion));
        }

        return (int) $raw;
    }

    /**
     * Wildcard-only requests come from generic HTTP clients: prefer the
     * configured default, then JSON, page, HTML and finally stream, skipping
     * modes the client explicitly excluded with q=0.
     */
    private function wildcardFallback(Request $request, AcceptHeader $accept): Mode
    {
        $candidates = [$this->resolveDefaultMode($request), Mode::Json, Mode::Page, Mode::Html, Mode::Stream];

        foreach ($candidates as $mode) {
            [$quality, $range] = $this->bestQualityFor($accept, $mode);

            if ($range !== null && $quality > 0.0) {
                return $mode;
            }
        }

        return $this->resolveDefaultMode($request);
    }

    private function resolveDefaultMode(Request $request): Mode
    {
        $route = $request->route();
        $routeDefault = $route instanceof Route ? $route->parameter('bridge.default_mode') : null;

        if (is_string($routeDefault) && ($mode = Mode::tryFrom($routeDefault)) !== null) {
            return $mode;
        }

        return $this->defaultMode;
    }

    /**
     * @return list<string>
     */
    private function acceptableTypes(): array
    {
        $types = [];

        foreach (Mode::inRankOrder() as $mode) {
            if ($mode->isRendering()) {
                array_push($types, ...$mode->mediaTypes());
            }
        }

        return $types;
    }
}
