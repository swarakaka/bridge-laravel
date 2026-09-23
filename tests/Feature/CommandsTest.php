<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\ExecutableFinder;

it('fails the doctor when the bus drops the roundtrip', function () {
    config()->set('bridge.stream.driver', 'null');

    $this->artisan('bridge:doctor')
        ->expectsOutputToContain('Some checks failed')
        ->assertFailed();
});

it('probes a stream url with the doctor', function () {
    config()->set('bridge.stream.driver', 'sync');
    Http::fake([
        'stream.test/events' => Http::response("event: bridge\ndata: {\"type\":\"ready\"}\n\n", 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'X-Accel-Buffering' => 'no',
        ]),
    ]);

    $this->artisan('bridge:doctor', ['--url' => 'http://stream.test/events', '--token' => 'abc'])
        ->expectsOutputToContain('Bridge streams look healthy.')
        ->assertSuccessful();

    Http::assertSent(fn (ClientRequest $request) => $request->hasHeader('Authorization', 'Bearer abc')
        && $request->hasHeader('Accept', 'text/event-stream'));
});

it('reports a failed probe from the doctor', function () {
    config()->set('bridge.stream.driver', 'sync');
    Http::fake(fn () => throw new ConnectionException('refused'));

    $this->artisan('bridge:doctor', ['--url' => 'http://stream.test/events'])
        ->expectsOutputToContain('refused')
        ->assertFailed();
});

it('refuses to start ssr without a bundle', function () {
    $this->artisan('bridge:ssr', ['--bundle' => 'bootstrap/missing.js'])
        ->expectsOutputToContain('SSR bundle not found at [bootstrap/missing.js]')
        ->assertFailed();
});

it('runs the ssr bundle with node and forwards the exit code', function () {
    if ((new ExecutableFinder)->find('node') === null) {
        $this->markTestSkipped('node is not installed.');
    }

    $bundle = 'bootstrap/bridge-ssr-test.js';
    $path = $this->app->basePath($bundle);
    config()->set('bridge.ssr.url', 'http://127.0.0.1:1');
    file_put_contents($path, "process.stdout.write('ssr:' + process.env.BRIDGE_SSR_URL); process.exitCode = 3;");

    try {
        $this->artisan('bridge:ssr', ['--bundle' => $bundle])
            ->expectsOutputToContain('ssr:http://127.0.0.1:1')
            ->assertExitCode(3);
    } finally {
        @unlink($path);
    }
});

it('rate limits stream connections per ip or user', function () {
    Route::middleware(['web', 'throttle:bridge-stream'])->get('/limited', fn () => 'ok');

    $this->get('/limited')->assertOk()->assertHeader('X-RateLimit-Limit', '30')->assertHeader('X-RateLimit-Remaining', '29');
    $this->get('/limited')->assertOk()->assertHeader('X-RateLimit-Remaining', '28');

    $user = new class extends User
    {
        protected $table = 'users';
    };
    $user->id = 1;

    $this->actingAs($user)->get('/limited')->assertOk()->assertHeader('X-RateLimit-Remaining', '29');
});
