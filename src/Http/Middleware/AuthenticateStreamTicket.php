<?php

declare(strict_types=1);

namespace Bridge\Http\Middleware;

use Bridge\Support\Headers;
use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
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
            $this->authenticate($this->auth->guard($guard), $userId);
        }

        return $next($request);
    }

    /**
     * Only the session guard has onceUsingId(). Token and request guards
     * (Sanctum, Passport, `token`) get the user from their provider and setUser().
     */
    private function authenticate(Guard $guard, string $userId): void
    {
        if ($guard instanceof SessionGuard) {
            $guard->onceUsingId($userId);

            return;
        }

        $provider = method_exists($guard, 'getProvider') ? $guard->getProvider() : null;

        if (! $provider instanceof UserProvider && $this->auth instanceof AuthManager) {
            $provider = $this->auth->createUserProvider();
        }

        $user = $provider instanceof UserProvider ? $provider->retrieveById($userId) : null;

        if ($user !== null) {
            $guard->setUser($user);
        }
    }
}
