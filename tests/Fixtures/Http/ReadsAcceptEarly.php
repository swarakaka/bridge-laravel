<?php

declare(strict_types=1);

namespace Bridge\Tests\Fixtures\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs before HandleBridgeRequests and reads the Accept header, which makes
 * Symfony cache the acceptable content types on the request.
 */
final class ReadsAcceptEarly
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->getAcceptableContentTypes();

        return $next($request);
    }
}
