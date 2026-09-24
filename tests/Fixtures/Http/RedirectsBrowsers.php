<?php

declare(strict_types=1);

namespace Bridge\Tests\Fixtures\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shaped like EnsureEmailIsVerified / RequirePassword: abort for API
 * callers, redirect browsers.
 */
final class RedirectsBrowsers
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->expectsJson()) {
            abort(403, 'Your email address is not verified.');
        }

        return redirect('/dashboard');
    }
}
