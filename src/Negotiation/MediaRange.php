<?php

declare(strict_types=1);

namespace Bridge\Negotiation;

/**
 * One media range from an Accept header: type/subtype, quality, parameters.
 */
final class MediaRange
{
    /**
     * @param  array<string, string>  $parameters  lower-cased names, raw values (without quotes)
     */
    public function __construct(
        public readonly string $type,
        public readonly string $subtype,
        public readonly float $quality,
        public readonly array $parameters,
        public readonly int $position,
    ) {}

    /**
     * Specificity: 2 for type/subtype, 1 for type/star, 0 for star/star.
     */
    public function specificity(): int
    {
        if ($this->type === '*') {
            return 0;
        }

        return $this->subtype === '*' ? 1 : 2;
    }

    public function matches(string $mediaType): bool
    {
        [$type, $subtype] = array_pad(explode('/', strtolower($mediaType), 2), 2, '');

        if ($this->type !== '*' && $this->type !== $type) {
            return false;
        }

        return $this->subtype === '*' || $this->subtype === $subtype;
    }

    public function parameter(string $name): ?string
    {
        return $this->parameters[strtolower($name)] ?? null;
    }

    public function mediaType(): string
    {
        return $this->type.'/'.$this->subtype;
    }
}
