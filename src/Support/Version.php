<?php

declare(strict_types=1);

namespace Bridge\Support;

use Closure;

/**
 * Resolves the asset build identifier used for X-Bridge-Build comparisons.
 */
final class Version
{
    private Closure|string|null $resolver = null;

    private bool $resolved = false;

    private ?string $value = null;

    public function __construct(private readonly ?string $manifestPath) {}

    public function set(Closure|string|null $resolver): void
    {
        $this->resolver = $resolver;
        $this->resolved = false;
    }

    public function get(): ?string
    {
        if ($this->resolved) {
            return $this->value;
        }

        $this->resolved = true;

        if ($this->resolver instanceof Closure) {
            $value = ($this->resolver)();
            $this->value = $value === null ? null : (string) $value;

            return $this->value;
        }

        if (is_string($this->resolver)) {
            return $this->value = $this->resolver;
        }

        if ($this->manifestPath !== null && is_file($this->manifestPath)) {
            return $this->value = hash_file('xxh128', $this->manifestPath) ?: null;
        }

        return $this->value = null;
    }
}
