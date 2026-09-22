<?php

declare(strict_types=1);

use Bridge\Facades\Bridge;
use Bridge\Ssr\HttpSsrGateway;
use Bridge\Ssr\SsrGateway;
use Bridge\Ssr\SsrResult;
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
