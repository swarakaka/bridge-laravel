<?php

declare(strict_types=1);

use Bridge\Stream\Bus\BusManager;
use Bridge\Stream\Bus\Cursor;
use Bridge\Stream\Bus\DatabaseBus;
use Bridge\Stream\Bus\Envelope;
use Bridge\Stream\Bus\NullBus;
use Bridge\Stream\Bus\RedisStreamsBus;
use Bridge\Stream\Bus\SyncBus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('bridge.stream.driver', 'database');
    config()->set('bridge.stream.prefix', 't');
    config()->set('bridge.stream.drivers.database.poll_ms', 5);
    $this->artisan('migrate')->run();
    $this->bus = app(BusManager::class)->driver();
});

it('resolves every driver from config', function () {
    $buses = app(BusManager::class);

    expect($this->bus)->toBeInstanceOf(DatabaseBus::class)
        ->and($this->bus->supportsReplay())->toBeTrue()
        ->and($buses->driver('sync'))->toBeInstanceOf(SyncBus::class)
        ->and($buses->driver('null'))->toBeInstanceOf(NullBus::class)
        ->and($buses->driver('redis'))->toBeInstanceOf(RedisStreamsBus::class);
});

it('publishes to every channel and reads in id order with replay', function () {
    $start = $this->bus->latestCursor(['a', 'b']);
    $one = $this->bus->publish(['a'], Envelope::make('x', ['n' => 1]));
    $two = $this->bus->publish(['b', 'a'], Envelope::make('y', ['n' => 'ü']));

    expect($start->fallback)->toBe('0')
        ->and(DB::table('bridge_stream_events')->pluck('channel')->unique()->values()->all())->toBe(['t:a', 't:b'])
        ->and($this->bus->latestCursor(['a'])->fallback)->toBe($two);

    $live = iterator_to_array($this->bus->read(['a', 'b'], $start, 0));

    expect($live)->toHaveCount(3)
        ->and($live[0]->id)->toBe($one)
        ->and($live[0]->channel)->toBe('a')
        ->and($live[0]->event)->toBe('x')
        ->and($live[0]->data)->toBe(['n' => 1])
        ->and($live[1]->channel)->toBe('b')
        ->and($live[2]->id)->toBe($two)
        ->and($live[2]->data)->toBe(['n' => 'ü'])
        ->and(array_unique(array_map(fn (Envelope $e) => $e->uuid, $live)))->toHaveCount(2);

    $onlyA = iterator_to_array($this->bus->read(['a'], Cursor::fromLastEventId($one), 0));

    expect($onlyA)->toHaveCount(1)
        ->and($onlyA[0]->id)->toBe($two)
        ->and($onlyA[0]->channel)->toBe('a')
        ->and(iterator_to_array($this->bus->read(['a', 'b'], Cursor::fromLastEventId($two), 0)))->toBe([]);
});

it('returns rows that became visible behind the cursor, once', function () {
    $insert = fn (int $id, string $event) => DB::table('bridge_stream_events')->insert([
        'id' => $id, 'uuid' => (string) Str::uuid(), 'channel' => 't:a', 'event' => $event, 'data' => '{}', 'created_at' => now(),
    ]);
    $insert(1, 'before');
    $cursor = $this->bus->latestCursor(['a']);

    // Row 3 commits first; row 2 (allocated earlier) commits after it has been read.
    $insert(3, 'first');
    $first = iterator_to_array($this->bus->read(['a'], $cursor, 0));
    expect(array_map(fn (Envelope $e) => $e->id, $first))->toBe(['3']);
    $cursor = $cursor->advance('a', '3');

    $insert(2, 'late');
    $late = iterator_to_array($this->bus->read(['a'], $cursor, 0));
    expect(array_map(fn (Envelope $e) => [$e->id, $e->event], $late))->toBe([['2', 'late']]);
    $cursor = $cursor->advance('a', '2');

    // Nothing is delivered twice, and the row from before the connection never shows up.
    expect(iterator_to_array($this->bus->read(['a'], $cursor, 0)))->toBe([]);
});

it('knows when pruning or a foreign id makes replay incomplete', function () {
    expect($this->bus->canReplayFrom(['a'], '0'))->toBeTrue()
        ->and($this->bus->canReplayFrom(['a'], '5'))->toBeFalse();

    $ids = array_map(fn (int $n) => $this->bus->publish(['a'], Envelope::make('x', ['n' => $n])), [1, 2, 3]);
    DB::table('bridge_stream_events')->where('id', $ids[0])->delete();

    expect($this->bus->canReplayFrom(['a'], $ids[0]))->toBeTrue()
        ->and($this->bus->canReplayFrom(['a'], '0'))->toBeFalse()
        ->and($this->bus->canReplayFrom(['a'], $ids[2]))->toBeTrue()
        ->and($this->bus->canReplayFrom(['a'], (string) ((int) $ids[2] + 1)))->toBeFalse()
        ->and($this->bus->canReplayFrom(['a'], '1758542400123-0'))->toBeFalse()
        ->and($this->bus->canReplayFrom(['a'], 'garbage'))->toBeFalse();
});

it('leaves no rows behind after bridge:doctor', function () {
    $this->artisan('bridge:doctor')->assertSuccessful();

    expect(DB::table('bridge_stream_events')->count())->toBe(0);
});

it('polls until the block deadline when nothing arrives', function () {
    $started = microtime(true);

    expect(iterator_to_array($this->bus->read(['a'], Cursor::start(), 30)))->toBe([])
        ->and(microtime(true) - $started)->toBeGreaterThanOrEqual(0.025);
});

it('prunes rows older than the retention window', function () {
    $this->bus->publish(['a'], Envelope::make('fresh', []));
    DB::table('bridge_stream_events')->insert([
        'uuid' => '00000000-0000-0000-0000-000000000000',
        'channel' => 't:a',
        'event' => 'stale',
        'data' => '{}',
        'created_at' => now()->subMinutes(120),
    ]);

    expect($this->bus->prune(60))->toBe(1)
        ->and(DB::table('bridge_stream_events')->pluck('event')->all())->toBe(['fresh']);

    $this->artisan('bridge:stream:prune', ['--minutes' => 60])
        ->expectsOutputToContain('Pruned 0 stream events older than 60 minutes.')
        ->assertSuccessful();
});

it('warns when the database bus is replaced by another driver', function () {
    app(BusManager::class)->extend('database', fn () => new NullBus)->forgetDrivers();

    $this->artisan('bridge:stream:prune')
        ->expectsOutputToContain('The database bus is not configured.')
        ->assertSuccessful();
});
