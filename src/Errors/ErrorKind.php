<?php

declare(strict_types=1);

namespace Bridge\Errors;

/**
 * Error kinds (spec/errors.md §2).
 */
enum ErrorKind: string
{
    case Validation = 'validation';
    case Unauthenticated = 'unauthenticated';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';
    case Csrf = 'csrf';
    case Throttled = 'throttled';
    case Conflict = 'conflict';
    case Http = 'http';
    case Server = 'server';

    public static function fromStatus(int $status): self
    {
        return match ($status) {
            401 => self::Unauthenticated,
            403 => self::Forbidden,
            404 => self::NotFound,
            409 => self::Conflict,
            419 => self::Csrf,
            422 => self::Validation,
            429 => self::Throttled,
            default => $status >= 500 ? self::Server : self::Http,
        };
    }
}
