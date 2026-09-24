<?php

declare(strict_types=1);

use Bridge\Facades\Bridge;
use Bridge\Ssr\HttpSsrGateway;
use Bridge\Ssr\NullSsrGateway;
use Bridge\Ssr\SsrGateway;
use Bridge\Ssr\SsrResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config()->set('bridge.shell.view', 'shell');
    Route::middleware('web')->get('/customers', fn () => Bridge::render('Customers/Index', ['n' => 1]));
});

it('renders on the client when ssr is disabled', function () {
    $this->html('/customers')
        ->assertSee('<div id="app" data-bridge></div>', false)
        ->assertDontSee('data-server-rendered', false);
});

it('embeds the ssr body and head fragments', function () {
    config()->set('bridge.ssr.enabled', true);
    $this->app->instance(SsrGateway::class, new class implements SsrGateway
    {
        public function render(array $page): ?SsrResult
        {
            return new SsrResult(['<title>Customers · SSR</title>'], '<main>'.$page['component'].'</main>');
        }
    });

    $this->html('/customers')
        ->assertSee('<div id="app" data-bridge data-server-rendered="true"><main>Customers/Index</main></div>', false)
        ->assertSee('<title>Customers · SSR</title>', false)
        ->assertSee('id="bridge-page"', false);
});

it('posts the page to the ssr server and falls back on failure', function () {
    config()->set('bridge.ssr.enabled', true);
    Http::fake([
        'ssr.test/render' => Http::sequence()
            ->push(['head' => ['<title>Rendered</title>'], 'body' => '<p>hello</p>'])
            ->push('boom', 500),
    ]);
    Log::spy();

    $gateway = new HttpSsrGateway(app(Factory::class), app('log'), 'http://ssr.test');
    $this->app->instance(SsrGateway::class, $gateway);

    $this->html('/customers')->assertSee('<p>hello</p>', false)->assertSee('<title>Rendered</title>', false);
    $this->html('/customers')->assertSee('<div id="app" data-bridge></div>', false);

    Http::assertSent(fn ($request) => $request->url() === 'http://ssr.test/render' && $request['component'] === 'Customers/Index');
});

it('skips ssr for static shells', function () {
    config()->set('bridge.ssr.enabled', true);
    config()->set('bridge.shell.embed', false);
    Http::fake();
    $this->app->instance(SsrGateway::class, new HttpSsrGateway(app(Factory::class), app('log'), 'http://ssr.test'));

    $this->html('/customers')->assertDontSee('data-server-rendered', false);
    Http::assertNothingSent();
});

it('falls back when the ssr server answers with an invalid payload or is unreachable', function () {
    config()->set('bridge.ssr.enabled', true);
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;

        return $calls === 1 ? Http::response(['head' => 'x', 'body' => 12]) : throw new ConnectionException('down');
    });
    Log::spy();

    $this->app->instance(SsrGateway::class, new HttpSsrGateway(app(Factory::class), app('log'), 'http://ssr.test/'));

    $this->html('/customers')->assertSee('<div id="app" data-bridge></div>', false);
    $this->html('/customers')->assertSee('<div id="app" data-bridge></div>', false);

    expect($calls)->toBe(2);
    Log::shouldHaveReceived('warning')->twice();
});

it('binds the http gateway only when ssr is enabled', function () {
    config()->set('bridge.ssr.enabled', true);
    $this->app->forgetInstance(SsrGateway::class);
    expect($this->app->make(SsrGateway::class))->toBeInstanceOf(HttpSsrGateway::class);

    config()->set('bridge.ssr.enabled', false);
    $this->app->forgetInstance(SsrGateway::class);
    expect($this->app->make(SsrGateway::class))->toBeInstanceOf(NullSsrGateway::class);
});

it('skips ssr for a cooldown after the server could not be reached', function () {
    config()->set('bridge.ssr.enabled', true);
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;

        throw new ConnectionException('timed out');
    });
    Log::spy();
    $cache = app('cache')->store('array');
    $this->app->instance(SsrGateway::class, new HttpSsrGateway(app(Factory::class), app('log'), 'http://ssr.test', 2.0, $cache, 10));

    $this->html('/customers')->assertSee('<div id="app" data-bridge></div>', false);
    $this->html('/customers')->assertSee('<div id="app" data-bridge></div>', false);
    expect($calls)->toBe(1);

    $cache->forget(HttpSsrGateway::DOWN_KEY);
    $this->html('/customers');
    expect($calls)->toBe(2);
});

it('keeps trying when the server answers with an error status', function () {
    config()->set('bridge.ssr.enabled', true);
    Http::fake(['*' => Http::response('boom', 500)]);
    Log::spy();
    $this->app->instance(SsrGateway::class, new HttpSsrGateway(app(Factory::class), app('log'), 'http://ssr.test', 2.0, app('cache')->store('array'), 10));

    $this->html('/customers');
    $this->html('/customers');
    Http::assertSentCount(2);
});
