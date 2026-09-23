<?php

declare(strict_types=1);

use Bridge\Props\Serializer;
use Bridge\Stream\Bus\Cursor;
use Bridge\Stream\Bus\Envelope;
use Bridge\Stream\Bus\SyncBus;
use Bridge\Stream\Publisher;
use Illuminate\Http\Request;

it('publishes every control message through the bus', function () {
    $bus = new SyncBus;
    $publisher = new Publisher($bus, app(Serializer::class), Request::create('/'), ['a']);

    $publisher->navigate('/customers', true);
    $publisher->progress('import', 0.25, 'Quarter');
    $publisher->notify('Saved', 'success', 'Done', ['id' => 1]);
    $publisher->prop('count', fn () => 3);
    $publisher->invalidate(['customers', 'stats']);
    $last = $publisher->end('deploy');

    $events = iterator_to_array($bus->read(['a'], Cursor::start(), 0));

    expect(array_map(fn (Envelope $e) => $e->data['type'], $events))->toBe(['navigate', 'progress', 'notification', 'prop', 'invalidate', 'end'])
        ->and($events[0]->data)->toBe(['type' => 'navigate', 'url' => '/customers', 'replace' => true])
        ->and($events[1]->data)->toBe(['type' => 'progress', 'id' => 'import', 'value' => 0.25, 'label' => 'Quarter'])
        ->and($events[2]->data['meta'])->toBe(['id' => 1])
        ->and($events[3]->data['value'])->toBe(3)
        ->and($events[4]->data['keys'])->toBe(['customers', 'stats'])
        ->and($events[5]->data)->toBe(['type' => 'end', 'reason' => 'deploy', 'reconnect' => true])
        ->and($events[5]->id)->toBe($last);
});

it('round-trips envelopes through arrays', function () {
    $envelope = Envelope::make('customer.created', ['id' => 1]);
    $array = $envelope->toArray();

    expect($array)->toMatchArray(['uuid' => $envelope->uuid, 'event' => 'customer.created', 'data' => ['id' => 1]])
        ->and($array['published_at'])->toBeFloat()
        ->and($envelope->isControl())->toBeFalse();

    $restored = Envelope::fromArray($array, '9', 'a');
    $delivered = $envelope->withDelivery('9', 'a');

    expect($restored->publishedAt)->toBe($envelope->publishedAt)
        ->and($restored->id)->toBe('9')
        ->and($restored->channel)->toBe('a')
        ->and($delivered->uuid)->toBe($envelope->uuid)
        ->and($delivered->id)->toBe('9')
        ->and(Envelope::fromArray(['data' => 'bad'], '1', 'b'))->toMatchObject(['event' => 'bridge', 'data' => [], 'publishedAt' => null]);
});
