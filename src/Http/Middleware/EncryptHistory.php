<?php

declare(strict_types=1);

namespace Bridge\Http\Middleware;

use Bridge\BridgeManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `bridge.encrypt-history`: pages of these routes are stored encrypted in the
 * client's history (spec/page.md §10). `bridge.encrypt-history:false` opts a
 * route out inside an encrypted group.
 */
final class EncryptHistory
{
    public function __construct(private readonly BridgeManager $bridge) {}

    public function handle(Request $request, Closure $next, string $encrypt = 'true'): Response
    {
        $this->bridge->encryptHistory(! in_array(strtolower($encrypt), ['false', '0', 'off'], true), $request);

        return $next($request);
    }
}
