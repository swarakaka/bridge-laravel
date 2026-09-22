<?php

declare(strict_types=1);

namespace Bridge\Representation;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Explicit cache directives requested by the application (PLAN §24).
 */
final class CacheOptions
{
    public function __construct(
        public readonly ?int $maxAge = null,
        public readonly bool $public = false,
        public readonly bool $force = false,
        public readonly ?\DateTimeInterface $lastModified = null,
    ) {}

    public function apply(Response $response, Request $request): void
    {
        $public = $this->public;

        // Guard rail: never emit `public` for an authenticated user unless forced.
        if ($public && ! $this->force && $request->user() !== null) {
            $public = false;
        }

        $directives = [$public ? 'public' : 'private'];

        if ($this->maxAge !== null) {
            $directives[] = 'max-age='.$this->maxAge;
            $directives[] = 'must-revalidate';
        } else {
            $directives[] = 'no-cache';
        }

        $response->headers->set('Cache-Control', implode(', ', $directives));

        if ($this->lastModified !== null) {
            $response->setLastModified(\DateTimeImmutable::createFromInterface($this->lastModified));
        }
    }
}
