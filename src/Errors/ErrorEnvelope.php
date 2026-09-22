<?php

declare(strict_types=1);

namespace Bridge\Errors;

/**
 * The mode-independent error representation (spec/errors.md §1).
 */
final class ErrorEnvelope
{
    /**
     * @param  array<string, list<string>>|null  $errors
     * @param  array<string, mixed>  $debug  extra members for JSON mode in debug
     * @param  array<string, string>  $headers  response headers (e.g. Retry-After)
     */
    public function __construct(
        public readonly int $status,
        public readonly ErrorKind $kind,
        public readonly string $message,
        public readonly ?array $errors = null,
        public readonly ?string $redirect = null,
        public readonly ?int $retryAfter = null,
        public readonly array $debug = [],
        public readonly array $headers = [],
    ) {}

    /**
     * The `error` member of the page-mode error object.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $error = [
            'status' => $this->status,
            'kind' => $this->kind->value,
            'message' => $this->message,
        ];

        if ($this->kind === ErrorKind::Validation) {
            $error['errors'] = $this->errors === [] || $this->errors === null ? new \stdClass : $this->errors;
        }

        if ($this->redirect !== null) {
            $error['redirect'] = $this->redirect;
        }

        if ($this->retryAfter !== null) {
            $error['retryAfter'] = $this->retryAfter;
        }

        return $error;
    }

    /**
     * The Laravel-native JSON body (spec/errors.md §3.3).
     *
     * @return array<string, mixed>
     */
    public function toJsonBody(): array
    {
        $body = ['message' => $this->message];

        if ($this->kind === ErrorKind::Validation) {
            $body['errors'] = $this->errors === [] || $this->errors === null ? new \stdClass : $this->errors;
        }

        return $body + $this->debug;
    }
}
