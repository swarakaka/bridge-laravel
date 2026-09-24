<?php

declare(strict_types=1);

namespace Bridge\Tests\Fixtures\Http;

use Bridge\Http\Middleware\HandleBridgeRequests;
use Illuminate\Http\Request;

/**
 * An application middleware as `bridge:middleware` generates it.
 */
final class SharesProps extends HandleBridgeRequests
{
    public function share(Request $request): array
    {
        return [
            'app' => 'Bridge',
            'path' => fn () => $request->path(),
        ];
    }
}
