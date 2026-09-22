<?php

declare(strict_types=1);

namespace Bridge\Negotiation;

/**
 * The representation a response uses. See packages/protocol/spec/negotiation.md.
 */
enum Mode: string
{
    case Html = 'html';
    case Page = 'page';
    case Json = 'json';
    case Stream = 'stream';

    public const PAGE_MEDIA_TYPE = 'application/vnd.bridge+json';

    /**
     * Media types that select this mode, most canonical first.
     *
     * @return list<string>
     */
    public function mediaTypes(): array
    {
        return match ($this) {
            self::Stream => ['text/event-stream'],
            self::Page => [self::PAGE_MEDIA_TYPE],
            self::Json => ['application/json'],
            self::Html => ['text/html', 'application/xhtml+xml'],
        };
    }

    /**
     * Tie-break rank: lower wins when match qualities are equal.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Stream => 1,
            self::Page => 2,
            self::Json => 3,
            self::Html => 4,
        };
    }

    public function isRendering(): bool
    {
        return $this !== self::Stream;
    }

    /**
     * @return list<self>
     */
    public static function inRankOrder(): array
    {
        return [self::Stream, self::Page, self::Json, self::Html];
    }
}
