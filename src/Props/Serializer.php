<?php

declare(strict_types=1);

namespace Bridge\Props;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use JsonSerializable;
use stdClass;
use Traversable;
use UnitEnum;

/**
 * Turns resolved prop values into JSON-ready PHP values using Laravel's own
 * serialization rules (spec/json.md §2).
 */
final class Serializer
{
    public function __construct(private readonly Container $container) {}

    public function serialize(mixed $value, Request $request): mixed
    {
        if ($value instanceof Closure) {
            return $this->serialize($this->container->call($value), $request);
        }

        if ($value instanceof PropHint) {
            return $this->serialize($value->resolve($this->container), $request);
        }

        if ($value instanceof ResourceCollection) {
            return $this->serializeResourceCollection($value, $request);
        }

        if ($value instanceof JsonResource) {
            return $this->serializeJsonResource($value, $request);
        }

        if ($value instanceof AbstractPaginator || $value instanceof AbstractCursorPaginator) {
            return $this->serialize($value->toArray(), $request);
        }

        if ($value instanceof Responsable) {
            $response = $value->toResponse($request);

            return $response instanceof JsonResponse
                ? $response->getData(true)
                : $response->getContent();
        }

        if ($value instanceof Arrayable) {
            return $this->serialize($value->toArray(), $request);
        }

        if ($value instanceof JsonSerializable) {
            return $this->serialize($value->jsonSerialize(), $request);
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof Traversable) {
            return $this->serialize(iterator_to_array($value), $request);
        }

        if ($value instanceof stdClass) {
            $result = new stdClass;

            foreach (get_object_vars($value) as $key => $item) {
                $result->{$key} = $this->serialize($item, $request);
            }

            return $result;
        }

        if (is_array($value)) {
            $result = [];

            foreach ($value as $key => $item) {
                $result[$key] = $this->serialize($item, $request);
            }

            return $result;
        }

        return $value;
    }

    /**
     * @return array<mixed>
     */
    private function serializeResourceCollection(ResourceCollection $collection, Request $request): array
    {
        $resource = $collection->resource;
        $paginated = $resource instanceof AbstractPaginator || $resource instanceof AbstractCursorPaginator;

        if ($paginated || $collection->with($request) !== [] || $collection->additional !== []) {
            // Laravel's own (paginated) resource response: {data, links, meta}.
            return (array) $collection->response($request)->getData(true);
        }

        return $this->serialize($collection->resolve($request), $request);
    }

    /**
     * @return array<mixed>
     */
    private function serializeJsonResource(JsonResource $resource, Request $request): array
    {
        $data = $this->serialize($resource->resolve($request), $request);

        return array_merge_recursive(
            is_array($data) ? $data : [],
            $resource->with($request),
            $resource->additional,
        );
    }
}
