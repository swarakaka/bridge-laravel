<?php

declare(strict_types=1);

use Bridge\Bridge;
use Bridge\Stream\Bus\Cursor;
use Bridge\Stream\Bus\Envelope;
use Bridge\Stream\Contracts\EventBus;
use Bridge\Stream\WatchChanges;
use Bridge\Support\Headers;
use Bridge\Tests\Fixtures\Models\WatchedCustomer;
use Bridge\Tests\Fixtures\Models\WatchedNote;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config()->set('bridge.stream.driver', 'sync');
    config()->set('queue.default', 'sync');

    Schema::create('customers', function (Blueprint $table) {
        $table->id();
        $table->unsignedInteger('tenant_id');
        $table->string('name');
        $table->softDeletes();
        $table->timestamps();
    });
    Schema::create('notes', function (Blueprint $table) {
        $table->id();
        $table->string('body');
        $table->timestamps();
    });
});

/**
 * Invalidate messages published on a channel so far.
 *
 * @return list<array<string, mixed>>
 */
function watchMessages(string $channel): array
{
    $events = iterator_to_array(app(EventBus::class)->read([$channel], Cursor::start(), 0), false);

    return array_values(array_map(fn (Envelope $e) => $e->data, $events));
}

it('publishes one message per channel for a request, with the hashed client', function () {
    Route::post('/customers', function () {
        foreach (['Acme', 'Globex', 'Initech'] as $name) {
            WatchedCustomer::create(['tenant_id' => 7, 'name' => $name]);
        }
        WatchedCustomer::create(['tenant_id' => 8, 'name' => 'Umbrella']);

        // Nothing is published before the response is sent.
        expect(watchMessages('tenant.7'))->toBe([]);

        return response()->noContent();
    });

    $this->withHeaders([Headers::CLIENT => 'Zm9vYmFyYmF6cXV4cXV1eA.7'])->post('/customers')->assertNoContent();

    expect(watchMessages('tenant.7'))->toBe([[
        'type' => 'invalidate',
        'keys' => [],
        'tags' => ['customers', 'customers.1', 'customers.2', 'customers.3'],
        'client' => 'ZnlfgKAmTkeQjF2rdZN7Lg.7',
    ]])
        ->and(watchMessages('tenant.8'))->toBe([[
            'type' => 'invalidate',
            'keys' => [],
            'tags' => ['customers', 'customers.4'],
            'client' => 'ZnlfgKAmTkeQjF2rdZN7Lg.7',
        ]]);
});

it('ignores a malformed client header and never publishes the token', function (string $header) {
    Route::post('/notes', fn () => WatchedNote::create(['body' => 'x']) ? response()->noContent() : null);

    $this->withHeaders([Headers::CLIENT => $header])->post('/notes')->assertNoContent();

    expect(watchMessages('bridge.watch'))->toBe([['type' => 'invalidate', 'keys' => [], 'tags' => ['notes', 'notes.1']]]);
})->with([
    'short token' => ['abc.1'],
    'no seq' => ['Zm9vYmFyYmF6cXV4cXV1eA'],
    'bad seq' => ['Zm9vYmFyYmF6cXV4cXV1eA.x'],
    'padding' => ['Zm9vYmFyYmF6cXV4cXV1eA==.1'],
]);

it('records changes after commit and drops rolled back ones', function () {
    $watch = app(WatchChanges::class);

    DB::transaction(function () use ($watch) {
        WatchedNote::create(['body' => 'kept']);

        expect($watch->hasPending())->toBeFalse();
    });

    expect($watch->hasPending())->toBeTrue();

    try {
        DB::transaction(function () {
            WatchedNote::create(['body' => 'lost']);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    $watch->flush();

    expect($watch->hasPending())->toBeFalse()
        ->and(watchMessages('bridge.watch'))->toBe([['type' => 'invalidate', 'keys' => [], 'tags' => ['notes', 'notes.1']]]);
});

it('records updates, deletes and restores but not unchanged saves', function () {
    $watch = app(WatchChanges::class);
    $customer = WatchedCustomer::create(['tenant_id' => 7, 'name' => 'Acme']);
    $watch->flush();

    $customer->save();
    expect($watch->hasPending())->toBeFalse();

    $customer->update(['name' => 'Acme Inc']);
    $watch->flush();
    $customer->delete();
    $watch->flush();
    $customer->restore();
    $watch->flush();

    expect(array_column(watchMessages('tenant.7'), 'tags'))->toBe(array_fill(0, 4, ['customers', 'customers.1']));
});

it('collapses record tags beyond max_tags', function () {
    config()->set('bridge.watch.max_tags', 2);
    app()->forgetInstance(WatchChanges::class);

    WatchedNote::create(['body' => 'a']);
    WatchedNote::create(['body' => 'b']);
    app(WatchChanges::class)->flush();
    WatchedNote::create(['body' => 'c']);
    WatchedNote::create(['body' => 'd']);
    WatchedNote::create(['body' => 'e']);
    app(WatchChanges::class)->flush();

    expect(array_column(watchMessages('bridge.watch'), 'tags'))->toBe([
        ['notes', 'notes.1', 'notes.2'],
        ['notes', 'notes.*'],
    ]);
});

it('uses bridge.watch.channels for models without streamOn()', function () {
    config()->set('bridge.watch.channels', ['a', 'b']);
    app()->forgetInstance(WatchChanges::class);

    WatchedNote::create(['body' => 'x']);
    app(WatchChanges::class)->flush();

    expect(watchMessages('a'))->toHaveCount(1)
        ->and(watchMessages('b'))->toHaveCount(1)
        ->and(watchMessages('bridge.watch'))->toBe([]);
});

it('publishes touches by hand, buffered like model changes', function () {
    $customer = (new WatchedCustomer)->forceFill(['id' => 5, 'tenant_id' => 7]);

    Bridge::to(['tenant.7'])->touch(WatchedCustomer::class, 'customers.*', 'reports', $customer);

    expect(watchMessages('tenant.7'))->toBe([]);

    app(WatchChanges::class)->flush();

    expect(watchMessages('tenant.7'))->toBe([[
        'type' => 'invalidate',
        'keys' => [],
        'tags' => ['customers', 'customers.5', 'customers.*', 'reports'],
    ]]);
});

it('refuses invalid touched tags', function () {
    Bridge::to('a')->touch('reports.*.x');
})->throws(InvalidArgumentException::class, 'Invalid watch tag [reports.*.x]');

it('flushes after each queued job, without a client', function () {
    $this->withHeaders([Headers::CLIENT => 'Zm9vYmFyYmF6cXV4cXV1eA.3']);

    dispatch(function () {
        WatchedNote::create(['body' => 'from a job']);
    });

    expect(watchMessages('bridge.watch'))->toBe([['type' => 'invalidate', 'keys' => [], 'tags' => ['notes', 'notes.1']]]);
});

it('flushes when a command finishes', function () {
    // A real `artisan` run reroutes Symfony's console events; test calls do
    // not. It must happen before the Artisan application is created.
    app(ConsoleKernel::class)->rerouteSymfonyCommandEvents();
    Artisan::command('notes:add', function () {
        WatchedNote::create(['body' => 'from a command']);
    });

    $this->artisan('notes:add')->assertSuccessful();

    expect(watchMessages('bridge.watch'))->toHaveCount(1);
});
