<?php

declare(strict_types=1);

use Bridge\Props\Lazy;
use Bridge\Props\Serializer;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\Response;

enum Suit
{
    case Hearts;
}

enum Status: string
{
    case Active = 'active';
}

it('serializes hints, paginators, responsables, resources, enums, dates, iterators and objects', function () {
    $serializer = app(Serializer::class);
    $request = Request::create('/');
    $serialize = fn (mixed $value) => $serializer->serialize($value, $request);

    $jsonResponsable = new class implements Responsable
    {
        public function toResponse($request): JsonResponse
        {
            return new JsonResponse(['ok' => true]);
        }
    };

    $plainResponsable = new class implements Responsable
    {
        public function toResponse($request): Response
        {
            return new Response('plain');
        }
    };

    $jsonSerializable = new class implements JsonSerializable
    {
        public function jsonSerialize(): array
        {
            return ['j' => Status::Active];
        }
    };

    $object = new stdClass;
    $object->when = new DateTimeImmutable('2026-01-02T03:04:05Z');
    $object->suit = Suit::Hearts;

    expect($serialize(new Lazy(fn () => 'lazy')))->toBe('lazy')
        ->and($serialize(new LengthAwarePaginator([1, 2], 2, 15)))->toMatchArray(['data' => [1, 2], 'total' => 2, 'per_page' => 15])
        ->and($serialize($jsonResponsable))->toBe(['ok' => true])
        ->and($serialize($plainResponsable))->toBe('plain')
        ->and($serialize($jsonSerializable))->toBe(['j' => 'active'])
        ->and($serialize(JsonResource::collection(collect([['id' => 1], ['id' => 2]]))))->toBe([['id' => 1], ['id' => 2]])
        ->and($serialize(new JsonResource(['id' => 3])))->toBe(['id' => 3])
        ->and($serialize(new ArrayIterator(['a' => Suit::Hearts])))->toBe(['a' => 'Hearts'])
        ->and($serialize($object))->toEqual((object) ['when' => '2026-01-02T03:04:05+00:00', 'suit' => 'Hearts'])
        ->and($serialize(['n' => null, 'f' => 1.5]))->toBe(['n' => null, 'f' => 1.5]);
});
