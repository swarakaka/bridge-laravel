<?php

declare(strict_types=1);

use Bridge\Facades\Bridge;
use Bridge\Stream\Bus\BusManager;
use Bridge\Stream\Bus\Cursor;
use Bridge\Stream\Bus\Envelope;
use Bridge\Stream\Bus\SyncBus;
use Bridge\Stream\ConnectionLimiter;
use Bridge\Stream\Contracts\EventBus;
use Bridge\Stream\Contracts\ReplayWindow;
use Bridge\Stream\Contracts\ShouldStream;
use Bridge\Stream\StreamMessage;
use Bridge\Stream\StreamWriter;
use Bridge\Tests\TestCase;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/**
 * Parses text/event-stream into frames: ['event' => ..., 'data' => array|string, 'id' => ?, 'retry' => ?, 'comment' => ?].
 *
 * @return list<array<string, mixed>>
 */
function sseFrames(string $body): array
{
    $frames = [];

    foreach (preg_split("/\n\n/", trim($body)) as $block) {
        $frame = [];

        foreach (explode("\n", $block) as $line) {
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, ':')) {
                $frame['comment'] = trim(substr($line, 1));

                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2) + [1 => '']);
            $frame[$field] = $field === 'data' ? (json_decode($value, true) ?? $value) : $value;
        }

        if ($frame !== []) {
            $frames[] = $frame;
        }
    }

    return $frames;
}

function streamBody(TestResponse $response): string
{
    return $response->streamedContent();
}

beforeEach(function () {
    config()->set('bridge.stream.driver', 'sync');
    config()->set('bridge.stream.heartbeat_ms', 15000);
    config()->set('bridge.stream.max_connections_per_user', 3);
    $this->bus = app(BusManager::class)->driver('sync');
    $this->app->instance(EventBus::class, $this->bus);

    Route::middleware('web')->get('/events', fn () => Bridge::stream()->channels(fn ($user) => ['customers'])->maxDuration(0));
});

function stream(TestCase $test, string $uri = '/events', array $headers = [])
{
    // Default headers persist across requests within a test; start clean each time.
    return $test->flushHeaders()->withHeaders(['Accept' => 'text/event-stream'] + $headers)->get($uri);
}

it('sends the stream headers and a ready event, then ends after max duration', function () {
    $response = stream($this);

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/event-stream; charset=utf-8')
        ->assertHeader('Cache-Control', 'no-cache, no-transform, private')
        ->assertHeader('X-Accel-Buffering', 'no');

    $frames = sseFrames(streamBody($response));

    expect($frames[0])->toBe(['retry' => '3000'])
        ->and($frames[1]['event'])->toBe('bridge')
        ->and($frames[1]['data'])->toBe(['type' => 'ready', 'protocol' => 1, 'replayed' => false, 'heartbeat' => 15000, 'maxDuration' => 0])
        ->and(end($frames)['data'])->toBe(['type' => 'end', 'reason' => 'max_duration', 'reconnect' => true])
        // The cursor, so the reconnect replays anything published in between.
        ->and(end($frames)['id'])->toBe('0');
});

it('delivers bus events with ids and skips events from before the connection', function () {
    Bridge::to('customers')->notify('old');

    // Publish after latestCursor() would be evaluated... the sync bus is in-process, so use replay to see it.
    $response = stream($this, '/events', ['Last-Event-ID' => '0']);
    $frames = sseFrames(streamBody($response));
    $events = array_values(array_filter($frames, fn ($f) => isset($f['event']) && ($f['data']['type'] ?? null) !== 'ready' && ($f['data']['type'] ?? null) !== 'end'));

    expect($frames[1]['data']['replayed'])->toBeTrue()
        ->and($events)->toHaveCount(1)
        ->and($events[0]['id'])->toBe('1')
        ->and($events[0]['data'])->toBe(['type' => 'notification', 'level' => 'info', 'title' => null, 'message' => 'old']);

    $fresh = sseFrames(streamBody(stream($this)));
    expect(array_filter($fresh, fn ($f) => ($f['data']['type'] ?? null) === 'notification'))->toBeEmpty();
});

it('answers replayed false when the bus lost events after Last-Event-ID', function () {
    $this->app->instance(EventBus::class, new class extends BusDouble
    {
        public function canReplayFrom(array $channels, string $lastEventId): bool
        {
            return false;
        }
    });

    $frames = sseFrames(streamBody(stream($this, '/events', ['Last-Event-ID' => '41'])));

    expect($frames[1]['data']['type'])->toBe('ready')
        ->and($frames[1]['data']['replayed'])->toBeFalse();
});

it('sends an event that arrives behind the cursor without an id', function () {
    $this->app->instance(EventBus::class, new BusDouble([
        Envelope::make('bridge', ['type' => 'invalidate', 'keys' => ['a']])->withDelivery('12', 'customers'),
        Envelope::make('bridge', ['type' => 'invalidate', 'keys' => ['b']])->withDelivery('11', 'customers'),
    ]));

    $events = array_values(array_filter(
        sseFrames(streamBody(stream($this))),
        fn ($f) => ($f['data']['type'] ?? null) === 'invalidate',
    ));

    expect($events)->toHaveCount(2)
        ->and($events[0]['id'])->toBe('12')
        ->and($events[1])->not->toHaveKey('id')
        ->and($events[1]['data']['keys'])->toBe(['b']);
});

it('publishes application events, props and invalidations through the facade', function () {
    Bridge::to(['customers', 'user.1'])->event('customer.created', ['id' => 12]);
    Bridge::to('customers')->prop('unreadCount', 3);
    Bridge::to('customers')->invalidate(['customers']);

    $frames = sseFrames(streamBody(stream($this, '/events', ['Last-Event-ID' => '0'])));
    $delivered = array_values(array_filter($frames, fn ($f) => isset($f['id']) && ($f['data']['type'] ?? null) !== 'end'));

    expect($delivered)->toHaveCount(3)
        ->and($delivered[0]['event'])->toBe('customer.created')
        ->and($delivered[0]['data'])->toBe(['id' => 12])
        ->and($delivered[1]['data'])->toBe(['type' => 'prop', 'key' => 'unreadCount', 'value' => 3, 'mode' => 'replace'])
        ->and($delivered[2]['data'])->toBe(['type' => 'invalidate', 'keys' => ['customers']]);
});

it('deduplicates a message published to two subscribed channels', function () {
    Route::middleware('web')->get('/multi', fn () => Bridge::stream()->channels(['a', 'b'])->maxDuration(0));
    Bridge::to(['a', 'b'])->notify('once');

    $frames = sseFrames(streamBody(stream($this, '/multi', ['Last-Event-ID' => '0'])));

    expect(array_filter($frames, fn ($f) => ($f['data']['type'] ?? null) === 'notification'))->toHaveCount(1);
});

it('ends the connection when an end signal is published while it is live', function () {
    // A bus that publishes an `end` during the first live read, as another process would.
    $live = new class($this->bus) implements EventBus
    {
        public function __construct(private SyncBus $inner) {}

        private bool $fired = false;

        public function publish(array $channels, Envelope $envelope): string
        {
            return $this->inner->publish($channels, $envelope);
        }

        public function read(array $channels, Cursor $since, int $blockMs): iterable
        {
            if (! $this->fired) {
                $this->fired = true;
                $this->inner->publish(['customers'], Envelope::make('bridge', StreamMessage::end('server_shutdown', true)->data));
            }

            return $this->inner->read($channels, $since, $blockMs);
        }

        public function latestCursor(array $channels): Cursor
        {
            return $this->inner->latestCursor($channels);
        }

        public function supportsReplay(): bool
        {
            return true;
        }
    };
    $this->app->instance(EventBus::class, $live);
    Route::middleware('web')->get('/live', fn () => Bridge::stream()->channels(['customers'])->maxDuration(5));

    $frames = sseFrames(streamBody(stream($this, '/live')));

    expect(end($frames)['data'])->toBe(['type' => 'end', 'reason' => 'server_shutdown', 'reconnect' => true]);
});

it('ignores replayed end signals but delivers later events', function () {
    Bridge::to('customers')->end('server_shutdown', true);
    Bridge::to('customers')->notify('after the end');

    $frames = sseFrames(streamBody(stream($this, '/events', ['Last-Event-ID' => '0'])));
    $types = array_map(fn ($f) => $f['data']['type'] ?? null, array_filter($frames, fn ($f) => isset($f['event'])));

    expect(array_values(array_filter($types, fn ($t) => $t === 'end')))->toBe(['end'])
        ->and(end($frames)['data']['reason'])->toBe('max_duration')
        ->and(array_filter($frames, fn ($f) => ($f['data']['message'] ?? null) === 'after the end'))->toHaveCount(1);
});

it('publishes ShouldStream events when dispatched', function () {
    $event = new class implements ShouldStream
    {
        public function streamOn(): array
        {
            return ['customers'];
        }

        public function toStream(): StreamMessage
        {
            return StreamMessage::invalidate('customers');
        }
    };

    event($event);

    $all = $this->bus->all();
    expect($all)->toHaveCount(1)->and($all[0]->data['type'])->toBe('invalidate');
});

it('authorizes client-requested channels and refuses unknown ones', function () {
    Bridge::channel('tenant.{id}', fn ($user, string $id) => $id === '7');
    Bridge::to('tenant.7')->notify('tenant');

    $ok = sseFrames(streamBody(stream($this, '/events?channels=tenant.7', ['Last-Event-ID' => '0'])));
    expect(array_filter($ok, fn ($f) => ($f['data']['message'] ?? null) === 'tenant'))->toHaveCount(1);

    $denied = sseFrames(streamBody(stream($this, '/events?channels=tenant.8')));
    expect($denied[2]['data'])->toMatchArray(['type' => 'error', 'status' => 403, 'kind' => 'forbidden', 'final' => true])
        ->and(end($denied)['data'])->toBe(['type' => 'end', 'reason' => 'unauthorized', 'reconnect' => false]);
});

it('runs one-off producers and ends without reconnect', function () {
    Route::middleware('web')->post('/export', fn () => Bridge::stream(function (StreamWriter $s) {
        $s->progress('export', 0.5, 'Half');
        $s->emit('export.done', ['rows' => 10]);
    }));

    $response = $this->withHeaders(['Accept' => 'text/event-stream'])->post('/export');
    $frames = sseFrames(streamBody($response));

    expect($frames[2]['data'])->toBe(['type' => 'progress', 'id' => 'export', 'value' => 0.5, 'label' => 'Half'])
        ->and($frames[3])->toMatchArray(['event' => 'export.done', 'data' => ['rows' => 10]])
        ->and(end($frames)['data'])->toBe(['type' => 'end', 'reason' => 'closed', 'reconnect' => false]);
});

it('answers 406 for non-stream accepts on stream routes and JSON errors before establishment', function () {
    $this->withHeaders(['Accept' => 'application/json'])->get('/events')
        ->assertStatus(406)
        ->assertJson(['message' => 'Not Acceptable', 'acceptable' => ['text/event-stream']]);

    Route::middleware('web')->get('/secret-events', fn () => throw new AuthenticationException);

    stream($this, '/secret-events')->assertStatus(401)->assertHeader('Content-Type', 'application/json')->assertExactJson(['message' => 'Unauthenticated.']);
});

it('sends heartbeats when idle', function () {
    Route::middleware('web')->get('/idle', function () {
        $t = 0.0;

        return Bridge::stream()->channels(['quiet'])->heartbeat(10)->maxDuration(1)
            // Each tick advances the fake clock by 0.4 s: two heartbeats fit before the 1 s deadline.
            ->clock(function () use (&$t) {
                return $t += 0.4;
            });
    });

    $frames = sseFrames(streamBody(stream($this, '/idle')));
    $heartbeats = array_filter($frames, fn ($f) => ($f['comment'] ?? null) === 'hb');

    expect(count($heartbeats))->toBeGreaterThanOrEqual(1)
        ->and(end($frames)['data']['reason'])->toBe('max_duration');
});

it('limits concurrent streams per user', function () {
    config()->set('bridge.stream.max_connections_per_user', 1);
    $limiter = app(ConnectionLimiter::class);
    $limiter->acquire('ip:127.0.0.1');

    $frames = sseFrames(streamBody(stream($this)));

    expect($frames[2]['data'])->toMatchArray(['type' => 'error', 'status' => 429, 'kind' => 'throttled', 'final' => false])
        ->and(end($frames)['data'])->toBe(['type' => 'end', 'reason' => 'closed', 'reconnect' => true]);

    $limiter->release('ip:127.0.0.1');
    expect(sseFrames(streamBody(stream($this)))[1]['data']['type'])->toBe('ready');
});

it('authenticates stream tickets from signed urls', function () {
    $this->loadLaravelMigrations();
    DB::table('users')->insert(['id' => 42, 'name' => 'Ticket', 'email' => 't@example.com', 'password' => 'x']);
    $user = User::query()->findOrFail(42);

    Route::middleware(['web', 'bridge.ticket'])->name('events')->get('/ticketed', fn () => Bridge::stream()->channels(fn ($user) => ['user.'.($user?->id ?? 'guest')])->maxDuration(0));

    $url = $this->actingAs($user)->app->make(\Bridge\Bridge::class)->streamTicket('events', ['scope' => 'demo']);
    expect($url)->toContain('bridge_user=42')->toContain('signature=');

    // A new request (no session) presents the ticket.
    $this->app['auth']->forgetGuards();
    auth()->logout();
    Bridge::to('user.42')->notify('for 42');

    $ticketed = $this->flushHeaders()->withHeaders(['Accept' => 'text/event-stream', 'Last-Event-ID' => '0'])->get($url);
    $frames = sseFrames(streamBody($ticketed));
    expect(array_filter($frames, fn ($f) => ($f['data']['message'] ?? null) === 'for 42'))->toHaveCount(1);

    // The in-process guard keeps the user between test requests; reset it as a new client would.
    $this->app['auth']->forgetGuards();
    $tampered = str_replace('bridge_user=42', 'bridge_user=43', $url);
    $frames = sseFrames(streamBody($this->flushHeaders()->withHeaders(['Accept' => 'text/event-stream', 'Last-Event-ID' => '0'])->get($tampered)));
    expect(array_filter($frames, fn ($f) => ($f['data']['message'] ?? null) === 'for 42'))->toBeEmpty();
});

it('runs the doctor and prune commands', function () {
    $this->artisan('bridge:doctor')->assertSuccessful();
    config()->set('bridge.stream.driver', 'database');
    $this->artisan('migrate')->run();
    $this->artisan('bridge:stream:prune')->assertSuccessful();
});

/**
 * A replaying bus that hands out a fixed batch once.
 */
class BusDouble implements EventBus, ReplayWindow
{
    /** @param list<Envelope> $batch */
    public function __construct(private array $batch = []) {}

    public function publish(array $channels, Envelope $envelope): string
    {
        return '0';
    }

    public function read(array $channels, Cursor $since, int $blockMs): iterable
    {
        [$batch, $this->batch] = [$this->batch, []];

        return $batch;
    }

    public function latestCursor(array $channels): Cursor
    {
        return new Cursor('10');
    }

    public function supportsReplay(): bool
    {
        return true;
    }

    public function canReplayFrom(array $channels, string $lastEventId): bool
    {
        return true;
    }
}
