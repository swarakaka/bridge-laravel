<?php

declare(strict_types=1);

namespace Bridge\Http\Middleware;

use Bridge\Support\Headers;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a stream request from a short-lived signed ticket URL
 * (Bridge::streamTicket()). Native EventSource cannot send Authorization headers.
 */
final class AuthenticateStreamTicket
{
    public const USER_PARAMETER = 'bridge_user';

    public function __construct(
        private readonly UrlGenerator $urls,
        private readonly AuthFactory $auth,
    ) {}

    public function handle(Request $request, Closure $next, ?string $guard = null): Response
    {
        $userId = $request->query(self::USER_PARAMETER);

        // lastEventId is appended by the client on reconnect; it grants nothing, so it is not signed.
        if (is_string($userId) && $userId !== '' && $this->urls->hasValidSignature($request, true, [Headers::LAST_EVENT_ID_QUERY])) {
            $this->auth->guard($guard)->onceUsingId($userId);
        }

        return $next($request);
    }
}
