<?php

declare(strict_types=1);

namespace Bridge\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as BaseVerifier;
use Illuminate\Http\Request;

/**
 * Laravel's CSRF verifier, skipped only for requests that authenticate with a
 * bearer token AND carry no session cookie. A cross-site form cannot set an
 * Authorization header, so cookie sessions keep full protection (PLAN §21.3).
 */
class VerifyCsrfToken extends BaseVerifier
{
    /**
     * @param  Request  $request
     */
    protected function inExceptArray($request): bool
    {
        if ($this->isBearerWithoutSession($request)) {
            return true;
        }

        return parent::inExceptArray($request);
    }

    protected function isBearerWithoutSession(Request $request): bool
    {
        if (! (bool) config('bridge.csrf.skip_for_bearer', true)) {
            return false;
        }

        if ($request->bearerToken() === null) {
            return false;
        }

        $sessionCookie = (string) config('session.cookie', '');

        return $sessionCookie === '' || ! $request->cookies->has($sessionCookie);
    }
}
