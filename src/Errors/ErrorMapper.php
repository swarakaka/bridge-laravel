<?php

declare(strict_types=1);

namespace Bridge\Errors;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Throwable;

/**
 * Exception → ErrorEnvelope (spec/errors.md §2). Handles both raw exceptions
 * and the HttpException wrappers Laravel's handler produces in prepareException().
 */
final class ErrorMapper
{
    public function __construct(private readonly Repository $config) {}

    public function map(Throwable $e, Request $request): ErrorEnvelope
    {
        $debug = (bool) $this->config->get('app.debug', false);

        if ($e instanceof ValidationException) {
            return new ErrorEnvelope(
                status: $e->status,
                kind: ErrorKind::Validation,
                message: $e->getMessage(),
                errors: $e->errors(),
            );
        }

        if ($e instanceof AuthenticationException) {
            return new ErrorEnvelope(
                status: 401,
                kind: ErrorKind::Unauthenticated,
                message: $e->getMessage() !== '' ? $e->getMessage() : 'Unauthenticated.',
                redirect: $this->loginUrl($e, $request),
            );
        }

        if ($e instanceof ThrottleRequestsException) {
            $retryAfter = $e->getHeaders()['Retry-After'] ?? null;

            return new ErrorEnvelope(
                status: 429,
                kind: ErrorKind::Throttled,
                message: $e->getMessage() !== '' ? $e->getMessage() : 'Too Many Attempts.',
                retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
                headers: array_map('strval', $e->getHeaders()),
            );
        }

        $previous = $e->getPrevious();

        if ($e instanceof TokenMismatchException || $previous instanceof TokenMismatchException) {
            return new ErrorEnvelope(419, ErrorKind::Csrf, 'CSRF token mismatch.');
        }

        if ($e instanceof AuthorizationException || $previous instanceof AuthorizationException) {
            $source = $e instanceof AuthorizationException ? $e : $previous;
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 403;

            return new ErrorEnvelope(
                status: $status,
                kind: ErrorKind::fromStatus($status),
                message: $source->getMessage() !== '' ? $source->getMessage() : 'This action is unauthorized.',
            );
        }

        if ($e instanceof ModelNotFoundException || $previous instanceof ModelNotFoundException) {
            $source = $e instanceof ModelNotFoundException ? $e : $previous;

            return new ErrorEnvelope(404, ErrorKind::NotFound, $debug ? $source->getMessage() : 'Not Found.');
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $kind = ErrorKind::fromStatus($status);
            $message = $e->getMessage() !== '' ? $e->getMessage() : (Response::$statusTexts[$status] ?? 'Error');

            if ($kind === ErrorKind::Forbidden && $e->getMessage() === '') {
                $message = 'This action is unauthorized.';
            }

            if ($kind === ErrorKind::NotFound && ! $debug) {
                $message = 'Not Found.';
            }

            if ($kind === ErrorKind::Server && ! $debug) {
                $message = 'Server Error.';
            }

            return new ErrorEnvelope(
                status: $status,
                kind: $kind,
                message: $message,
                headers: array_map('strval', $e->getHeaders()),
            );
        }

        return new ErrorEnvelope(
            status: 500,
            kind: ErrorKind::Server,
            message: $debug ? $e->getMessage() : 'Server Error.',
            debug: $debug ? $this->debugDetails($e) : [],
        );
    }

    private function loginUrl(AuthenticationException $e, Request $request): ?string
    {
        try {
            $redirect = $e->redirectTo($request);
        } catch (RouteNotFoundException) {
            $redirect = null;
        }

        if (is_string($redirect) && $redirect !== '') {
            return $redirect;
        }

        $configured = $this->config->get('bridge.auth.login_url');

        return is_string($configured) && $configured !== '' ? $configured : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function debugDetails(Throwable $e): array
    {
        return [
            'exception' => $e::class,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => array_map(
                static fn (array $frame): array => array_diff_key($frame, ['args' => true]),
                $e->getTrace(),
            ),
        ];
    }
}
