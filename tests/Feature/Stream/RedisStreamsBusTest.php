<?php

declare(strict_types=1);

use Bridge\Stream\Bus\Cursor;
use Bridge\Stream\Bus\Envelope;
use Bridge\Stream\Bus\RedisStreamsBus;
use Illuminate\Support\Facades\Redis;

/**
 * Runs against a real Redis when BRIDGE_TEST_REDIS=1 (CI provides a service).
 */
beforeEach(function () {
    if (! filter_var(getenv('BRIDGE_TEST_REDIS'), FILTER_VALIDATE_BOOLEAN)) {
        $this->markTestSkipped('Set BRIDGE_TEST_REDIS=1 with a Redis server to run.');
    }

    config()->set('database.redis.default.host', getenv('REDIS_HOST') ?: '127.0.0.1');
    config()->set('database.redis.default.database', 15);
    $this->prefix = 'bridge-test-'.bin2hex(random_bytes(3));
    $this->bus = new RedisStreamsBus(app('redis'), null, 100, $this->prefix);
});

afterEach(function () {
    if (isset($this->prefix)) {
        foreach (Redis::connection()->keys($this->prefix.':*') as $key) {
            Redis::connection()->del($key);
        }
    }
});

it('publishes, reads live, replays by id and orders across channels', function () {
    $start = $this->bus->latestCursor(['a', 'b']);
    usleep(2000);
    $one = $this->bus->publish(['a'], Envelope::make('x', ['n' => 1]));
    $two = $this->bus->publish(['b', 'a'], Envelope::make('y', ['n' => 2]));

    $live = iterator_to_array($this->bus->read(['a', 'b'], $start, 50));
    $ids = array_map(fn ($e) => $e->id, $live);

    expect($one)->toMatch('/^\d+-\d+$/')
        ->and(count($live))->toBe(3)
        ->and($ids[0])->toBe($one)
        ->and(Cursor::compare($ids[2], $ids[0]))->toBeGreaterThan(0)
        ->and(array_unique(array_map(fn ($e) => $e->uuid, $live)))->toHaveCount(2);

    $replayed = iterator_to_array($this->bus->read(['a'], Cursor::fromLastEventId($one), 50));
    expect($replayed)->toHaveCount(1)->and($replayed[0]->id)->toBe($two)
        ->and($this->bus->supportsReplay())->toBeTrue()
        ->and(iterator_to_array($this->bus->read(['a', 'b'], Cursor::fromLastEventId($two), 100)))->toBe([]);
});

it('refuses replay once trimming dropped events after Last-Event-ID', function () {
    $bus = new RedisStreamsBus(app('redis'), null, 5, $this->prefix);
    $first = $bus->publish(['a'], Envelope::make('x', ['n' => 0]));

    expect($bus->canReplayFrom(['a', 'never-used'], $first))->toBeTrue()
        ->and($bus->canReplayFrom(['a'], '42'))->toBeFalse()
        ->and($bus->canReplayFrom(['a'], (string) (time() + 3600).'000-0'))->toBeFalse();

    // Exact trimming, so the drop is certain regardless of node sizes.
    for ($n = 1; $n <= 20; $n++) {
        $last = $bus->publish(['a'], Envelope::make('x', ['n' => $n]));
    }
    Redis::connection()->executeRaw(['XTRIM', $this->prefix.':stream:a', 'MAXLEN', '5']);

    expect($bus->canReplayFrom(['a'], $first))->toBeFalse()
        ->and($bus->canReplayFrom(['a'], $last))->toBeTrue();
});
