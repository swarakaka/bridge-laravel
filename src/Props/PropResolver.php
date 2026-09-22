<?php

declare(strict_types=1);

namespace Bridge\Props;

use Bridge\Negotiation\Mode;
use Bridge\Page\Page;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

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

        foreach ($props as $key => $value) {
            $key = (string) $key;

            if ($value instanceof Always) {
                $resolved[$key] = $this->serializer->serialize($value->resolve($this->container), $request);

                continue;
            }

            if (! $selection->includes($key)) {
                continue;
            }

            if ($value instanceof Lazy) {
                if (! $selection->isPartial() || $selection->only === []) {
                    continue;
                }

                $value = $value->resolve($this->container);
            } elseif ($value instanceof Merge) {
                $merge[] = $key;
                $value = $value->resolve($this->container);
            } elseif ($value instanceof Deferred) {
                $inlineDeferred = $selection->isPartial() || ($mode === Mode::Json && $this->resolveDeferredInJson);

                if (! $inlineDeferred) {
                    $deferred[$value->group][] = $key;

                    continue;
                }

                $value = $value->resolve($this->container);
            }

            $serialized = $this->serializer->serialize($value, $request);
            $resolved[$key] = $this->applyNestedSelection($key, $serialized, $selection);
        }

        return new ResolvedProps($resolved, $deferred, $selection->isPartial(), $merge);
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
