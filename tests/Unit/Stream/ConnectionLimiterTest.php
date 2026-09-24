<?php

declare(strict_types=1);

use Bridge\Stream\ConnectionLimiter;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;

it('frees the slot of a stream that stopped renewing its lease', function () {
    $now = 1000;
    $limiter = new ConnectionLimiter(new Repository(new ArrayStore), 2, 360, function () use (&$now): int {
        return $now;
    });

    $alive = $limiter->acquire('user:1', 50);
    $killed = $limiter->acquire('user:1', 50);

    expect($alive)->not->toBeNull()
        ->and($killed)->not->toBeNull()
        ->and($limiter->acquire('user:1', 50))->toBeNull();

    // The live stream renews on its heartbeats; the killed worker never does again.
    $now += 40;
    $limiter->renew('user:1', $alive, 50);
    $now += 20;

    expect($limiter->current('user:1'))->toBe(1)
        ->and($limiter->acquire('user:1', 50))->not->toBeNull()
        ->and($limiter->acquire('user:1', 50))->toBeNull();

    $limiter->release('user:1', $alive);
    expect($limiter->current('user:1'))->toBe(1);
});

it('keeps a producer lease for the whole stream lifetime and releases without a lease id', function () {
    $now = 0;
    $limiter = new ConnectionLimiter(new Repository(new ArrayStore), 1, 360, function () use (&$now): int {
        return $now;
    });

    $limiter->acquire('ip:1');
    $now += 300;
    expect($limiter->acquire('ip:1'))->toBeNull();

    $limiter->release('ip:1');
    expect($limiter->current('ip:1'))->toBe(0);
});

it('falls back to a counter that never goes below zero on stores without locks', function () {
    $store = new class implements Store
    {
        /** @var array<string, mixed> */
        public array $items = [];

        public function get($key): mixed
        {
            return $this->items[$key] ?? null;
        }

        public function many(array $keys): array
        {
            return array_map(fn ($k) => $this->get($k), array_combine($keys, $keys));
        }

        public function put($key, $value, $seconds): bool
        {
            $this->items[$key] = $value;

            return true;
        }

        public function putMany(array $values, $seconds): bool
        {
            $this->items = array_merge($this->items, $values);

            return true;
        }

        public function increment($key, $value = 1): int|bool
        {
            return $this->items[$key] = (int) ($this->items[$key] ?? 0) + $value;
        }

        public function decrement($key, $value = 1): int|bool
        {
            return $this->increment($key, -$value);
        }

        public function forever($key, $value): bool
        {
            return $this->put($key, $value, 0);
        }

        public function forget($key): bool
        {
            unset($this->items[$key]);

            return true;
        }

        public function flush(): bool
        {
            $this->items = [];

            return true;
        }

        public function getPrefix(): string
        {
            return '';
        }

        public function touch($key, $seconds): bool
        {
            return true;
        }
    };
    $limiter = new ConnectionLimiter(new Repository($store), 1, 360);

    expect($limiter->acquire('user:2'))->not->toBeNull()
        ->and($limiter->acquire('user:2'))->toBeNull();

    $limiter->release('user:2');
    $limiter->release('user:2');
    expect($limiter->current('user:2'))->toBe(0);
});
