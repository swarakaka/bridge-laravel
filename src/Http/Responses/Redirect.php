<?php

declare(strict_types=1);

namespace Bridge\Http\Responses;

/**
 * A resolved redirect: target, attached data, flash and status hints.
 */
final class Redirect
{
    /**
     * @param  array<string, mixed>  $data  already serialized
     * @param  array<string, mixed>|null  $flash
     */
    public function __construct(
        public readonly string $url,
        public readonly array $data = [],
        public readonly ?array $flash = null,
        public readonly ?int $jsonStatus = null,
        public readonly bool $external = false,
    ) {}
}
