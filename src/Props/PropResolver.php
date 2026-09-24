<?php

declare(strict_types=1);

namespace Bridge\Props;

use Bridge\Negotiation\Mode;
use Bridge\Page\Page;
use Bridge\Support\Headers;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use LogicException;

/**
 * Applies partial selection and delivery hints, then serializes (PLAN §12).
 */
final class PropResolver
{
    public function __construct(
        private readonly Container $container,
        private readonly Serializer $serializer,
        private readonly bool $resolveDeferredInJson = true,
    ) {}

    /**
     * @param  array<string, mixed>  $shared  shared props; page props win on conflict
     */
    public function resolve(Page $page, array $shared, Mode $mode, Request $request): ResolvedProps
    {
        $selection = PartialSelection::fromRequest($request, $page->component);
        $props = array_merge($shared, $page->props);
        $resolved = [];
        $deferred = [];
        $merge = [];
        $prepend = [];
        $deepMerge = [];
        $matchOn = [];
        $once = [];
        // Held once keys matter only to page clients; HTML shells always carry the values.
        $heldKeys = $mode === Mode::Page ? $this->heldOnceKeys($request) : [];

        foreach ($props as $key => $value) {
            $key = (string) $key;

            if ($value instanceof Always) {
                $resolved[$key] = $this->serializer->serialize($this->unwrap($key, $value), $request);

                continue;
            }

            if (! $selection->includes($key)) {
                continue;
            }

            // Delivery, then once, then merge (PLAN §13.3).
            $options = $value instanceof PropHint ? $value->onceOptions() : null;
            $onceEntry = null;
            $held = false;

            if ($options !== null && $mode !== Mode::Json) {
                $onceEntry = ['key' => $options->keyFor($key), 'expiresAt' => $options->expiresAt()];
                // Named in X-Bridge-Only: an explicit reload is a refresh.
                $named = in_array($key, $selection->onlyTopLevel(), true);
                $held = isset($heldKeys[$onceEntry['key']]) && ! $options->fresh && ! $named;
            }

            if ($value instanceof Lazy && (! $selection->isPartial() || $selection->only === [])) {
                // Absent unless named; a held lazy-once value is still filled in by the client.
                if ($held && $onceEntry !== null) {
                    $once[$key] = $onceEntry;
                }

                continue;
            }

            if ($value instanceof Deferred && ! ($selection->isPartial() || ($mode === Mode::Json && $this->resolveDeferredInJson))) {
                // A held deferred-once value is neither sent nor deferred: the client fills it in.
                if ($held && $onceEntry !== null) {
                    $once[$key] = $onceEntry;
                } else {
                    $deferred[$value->group][] = $key;
                }

                continue;
            }

            if ($onceEntry !== null) {
                $once[$key] = $onceEntry;

                if ($held) {
                    continue;
                }
            }

            $mergeOptions = $value instanceof PropHint ? $value->mergeOptions() : null;

            if ($mergeOptions !== null) {
                match ($mergeOptions->mode) {
                    MergeOptions::PREPEND => $prepend[] = $key,
                    MergeOptions::DEEP => $deepMerge[] = $key,
                    default => $merge[] = $key,
                };

                if ($mergeOptions->matchOn !== []) {
                    $matchOn[$key] = $mergeOptions->matchOn;
                }
            }

            if ($value instanceof PropHint) {
                $value = $this->unwrap($key, $value);
            }

            $serialized = $this->serializer->serialize($value, $request);
            $resolved[$key] = $this->applyNestedSelection($key, $serialized, $selection);
        }

        return new ResolvedProps($resolved, $deferred, $selection->isPartial(), $merge, $once, $prepend, $deepMerge, $matchOn);
    }

    /**
     * Resolves a hint. A hint whose value turns out to be another hint (for
     * example a closure returning Bridge::once()) is refused: nesting has no
     * defined meaning on the wire.
     */
    private function unwrap(string $key, PropHint $hint): mixed
    {
        $value = $hint->resolve($this->container);

        if ($value instanceof PropHint) {
            throw new LogicException("Prop [{$key}]: Bridge prop hints cannot be nested.");
        }

        return $value;
    }

    /**
     * @return array<string, true>
     */
    private function heldOnceKeys(Request $request): array
    {
        $header = (string) $request->headers->get(Headers::ONCE, '');
        $held = [];

        foreach (explode(',', $header) as $key) {
            $key = trim($key);

            if ($key !== '') {
                $held[$key] = true;
            }
        }

        return $held;
    }

    private function applyNestedSelection(string $key, mixed $value, PartialSelection $selection): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $nestedOnly = $selection->nestedOnly($key);

        if ($nestedOnly !== []) {
            $picked = [];

            foreach ($nestedOnly as $path) {
                if (Arr::has($value, $path)) {
                    Arr::set($picked, $path, Arr::get($value, $path));
                }
            }

            return $picked;
        }

        $nestedExcept = $selection->nestedExcept($key);

        if ($nestedExcept !== []) {
            Arr::forget($value, $nestedExcept);
        }

        return $value;
    }
}
