<?php

declare(strict_types=1);

namespace Bridge\Ssr;

final class NullSsrGateway implements SsrGateway
{
    public function render(array $page): ?SsrResult
    {
        return null;
    }
}
