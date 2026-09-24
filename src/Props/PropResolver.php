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
        $held = $mode === Mode::Page ? $this->heldOnceKeys($request) : [];

        foreach ($props as $key => $value) {
            $key = (string) $key;

            if ($value instanceof Always) {
                $resolved[$key] = $this->serializer->serialize($this->unwrap($key, $value), $request);

                continue;
            }

            if (! $selection->includes($key)) {
                continue;
            }

            if ($value instanceof Lazy) {
                if (! $selection->isPartial() || $selection->only === []) {
                    continue;
                }

                $value = $this->unwrap($key, $value);
            } elseif ($value instanceof Once) {
                $onceKey = $value->keyFor($key);

                if ($mode !== Mode::Json) {
                    $once[$key] = ['key' => $onceKey, 'expiresAt' => $value->expiresAt()];
                }

                // Named in X-Bridge-Only: an explicit reload is a refresh.
                $named = in_array($key, $selection->onlyTopLevel(), true);

                if (isset($held[$onceKey]) && ! $value->fresh && ! $named) {
                    continue;
                }

                $value = $this->unwrap($key, $value);
            } elseif ($value instanceof Merge) {
                match ($value->mode) {
                    Merge::PREPEND => $prepend[] = $key,
                    Merge::DEEP => $deepMerge[] = $key,
                    default => $merge[] = $key,
                };

                if ($value->matchOn !== []) {
                    $matchOn[$key] = $value->matchOn;
                }

                $value = $this->unwrap($key, $value);
            } elseif ($value instanceof Deferred) {
                $inlineDeferred = $selection->isPartial() || ($mode === Mode::Json && $this->resolveDeferredInJson);

                if (! $inlineDeferred) {
                    $deferred[$value->group][] = $key;

                    continue;
                }

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
