<?php

declare(strict_types=1);

namespace Bridge\Props;

use Closure;
use DateInterval;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * A prop value with a delivery (plain, lazy, deferred or always; see
 * spec/page.md §4) and optional modifiers: merge (§3) and once (§11), for
 * example `Bridge::defer(fn () => ...)->once(ttl: 3600)`. Modifiers return
 * copies, because shared hints outlive requests.
 */
abstract class PropHint
{
    private ?MergeOptions $mergeOptions = null;

    private ?OnceOptions $onceOptions = null;

    public function __construct(protected readonly mixed $value) {}

    public function resolve(Container $container): mixed
    {
        return $this->value instanceof Closure
            ? $container->call($this->value)
            : $this->value;
    }

    public function mergeOptions(): ?MergeOptions
    {
        return $this->mergeOptions;
    }

    public function onceOptions(): ?OnceOptions
    {
        return $this->onceOptions;
    }

    /** Combine with the current value on opted-in partial reloads, appending (§3). */
    public function merge(): static
    {
        return $this->withMerge(MergeOptions::APPEND, $this->mergeOptions->matchOn ?? []);
    }

    /** As merge(), incoming items first. */
    public function prepend(): static
    {
        return $this->withMerge(MergeOptions::PREPEND, $this->mergeOptions->matchOn ?? []);
    }

    /** As merge(), appending (the default mode). */
    public function append(): static
    {
        return $this->withMerge(MergeOptions::APPEND, $this->mergeOptions->matchOn ?? []);
    }

    /** As merge(), objects merged key by key at every depth. */
    public function deep(): static
    {
        return $this->withMerge(MergeOptions::DEEP, $this->mergeOptions->matchOn ?? []);
    }

    /**
     * Items whose key matches an item already shown replace it. The last path
     * segment is the item key: `id` for a list, `data.id` for a paginator.
     * Implies merge() when no merge mode was chosen; no paths clears matching.
     */
    public function matchOn(string ...$paths): static
    {
        return $this->withMerge($this->mergeOptions->mode ?? MergeOptions::APPEND, array_values($paths));
    }

    /** Send once, then let the client reuse the value until `$ttl` passes (§11). */
    public function once(?string $key = null, DateInterval|int|null $ttl = null): static
    {
        return $this->withOnce(new OnceOptions($key, $ttl, $this->onceOptions->fresh ?? false));
    }

    /** Send the value even to a client that holds it. Requires once(). */
    public function fresh(bool $fresh = true): static
    {
        if ($this->onceOptions === null) {
            throw new LogicException('fresh() applies to once props: call once() first.');
        }

        $options = $this->onceOptions;

        return $this->withOnce(new OnceOptions($options->key, $options->ttl, $fresh));
    }

    /**
     * @param  MergeOptions::APPEND|MergeOptions::PREPEND|MergeOptions::DEEP  $mode
     * @param  list<string>  $matchOn
     */
    protected function withMerge(string $mode, array $matchOn): static
    {
        $this->refuse('merge');

        if ($this->onceOptions !== null) {
            throw new LogicException('A prop cannot be both once and merge: a held value is never sent, so there is nothing to merge into.');
        }

        $copy = clone $this;
        $copy->mergeOptions = new MergeOptions($mode, $matchOn);

        return $copy;
    }

    protected function withOnce(OnceOptions $options): static
    {
        $this->refuse('once');

        if ($this->mergeOptions !== null) {
            throw new LogicException('A prop cannot be both merge and once: a held value is never sent, so there is nothing to merge into.');
        }

        $copy = clone $this;
        $copy->onceOptions = $options;

        return $copy;
    }

    /** For shorthand classes (Bridge::merge(), Bridge::once()) setting their modifier at construction. */
    protected function initMerge(MergeOptions $options): void
    {
        $this->mergeOptions = $options;
    }

    protected function initOnce(OnceOptions $options): void
    {
        $this->onceOptions = $options;
    }

    /** Deliveries that cannot take a modifier override this. */
    protected function refuse(string $modifier): void {}
}
