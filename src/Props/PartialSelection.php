<?php

declare(strict_types=1);

namespace Bridge\Props;

use Bridge\Support\Headers;
use Illuminate\Http\Request;

/**
 * Partial-selection headers, already validated against the current component.
 */
final class PartialSelection
{
    /**
     * @param  list<string>  $only
     * @param  list<string>  $except
     */
    private function __construct(
        public readonly array $only,
        public readonly array $except,
    ) {}

    public static function fromRequest(Request $request, string $component): self
    {
        $only = self::split($request->headers->get(Headers::ONLY));
        $except = self::split($request->headers->get(Headers::EXCEPT));

        if ($only === [] && $except === []) {
            return self::none();
        }

        $requestedComponent = $request->headers->get(Headers::COMPONENT);

        if ($requestedComponent !== null && $requestedComponent !== $component) {
            return self::none();
        }

        // X-Bridge-Only wins over X-Bridge-Except (spec/headers.md).
        return $only !== [] ? new self($only, []) : new self([], $except);
    }

    public static function none(): self
    {
        return new self([], []);
    }

    public function isPartial(): bool
    {
        return $this->only !== [] || $this->except !== [];
    }

    /**
     * Top-level keys named in `only` (dot keys reduced to their first segment).
     *
     * @return list<string>
     */
    public function onlyTopLevel(): array
    {
        return array_values(array_unique(array_map(
            static fn (string $key): string => explode('.', $key, 2)[0],
            $this->only,
        )));
    }

    /**
     * Whether a top-level key should be included, ignoring hints.
     */
    public function includes(string $key): bool
    {
        if ($this->only !== []) {
            return in_array($key, $this->onlyTopLevel(), true);
        }

        return ! in_array($key, $this->except, true);
    }

    /**
     * Dot keys under a top-level key, e.g. only=customers.data → ['data'].
     *
     * @return list<string>
     */
    public function nestedOnly(string $key): array
    {
        $nested = [];

        foreach ($this->only as $candidate) {
            if (str_starts_with($candidate, $key.'.')) {
                $nested[] = substr($candidate, strlen($key) + 1);
            }
        }

        return $nested;
    }

    /**
     * Dot keys under a top-level key for `except`.
     *
     * @return list<string>
     */
    public function nestedExcept(string $key): array
    {
        $nested = [];

        foreach ($this->except as $candidate) {
            if (str_starts_with($candidate, $key.'.')) {
                $nested[] = substr($candidate, strlen($key) + 1);
            }
        }

        return $nested;
    }

    /**
     * @return list<string>
     */
    private static function split(?string $header): array
    {
        if ($header === null || trim($header) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $header)), static fn ($k) => $k !== ''));
    }
}
