<?php

declare(strict_types=1);

namespace Bridge\Ssr;

/**
 * Renders a page object to HTML on a separate SSR process (PLAN §26).
 */
interface SsrGateway
{
    /**
     * @param  array<string, mixed>  $page  the page object as embedded in the shell
     */
    public function render(array $page): ?SsrResult;
}
