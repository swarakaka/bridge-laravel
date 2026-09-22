<?php

declare(strict_types=1);

namespace Bridge\Ssr;

final class SsrResult
{
    /**
     * @param  list<string>  $head  HTML fragments for <head> (title, meta)
     * @param  string  $body  HTML for the root element's content
     */
    public function __construct(
        public readonly array $head,
        public readonly string $body,
    ) {}
}
