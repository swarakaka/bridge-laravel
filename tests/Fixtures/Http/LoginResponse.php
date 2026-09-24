<?php

declare(strict_types=1);

namespace Bridge\Tests\Fixtures\Http;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shaped like Laravel Fortify's LoginResponse / TwoFactorLoginResponse:
 * JSON for API callers, a redirect for browsers.
 */
final class LoginResponse implements Responsable
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        return $request->wantsJson()
            ? new JsonResponse(['two_factor' => false])
            : redirect()->intended('/dashboard');
    }
}
