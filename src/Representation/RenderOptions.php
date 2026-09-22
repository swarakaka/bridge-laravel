<?php

declare(strict_types=1);

namespace Bridge\Representation;

/**
 * Per-response rendering options set through the PageResponse fluent API.
 */
final class RenderOptions
{
    public function __construct(
        public readonly ?bool $embed = null,
        public readonly ?CacheOptions $cache = null,
        public readonly ?string $shellView = null,
        public readonly int $status = 200,
    ) {}
}
