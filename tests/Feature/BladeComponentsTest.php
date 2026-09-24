<?php

declare(strict_types=1);

use Bridge\Bridge;
use Bridge\Ssr\SsrGateway;
use Bridge\Ssr\SsrResult;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('web')->get('/customers', fn () => Bridge::render('Customers/Index', ['n' => 1]));
});

it('renders the same shell as the directives, with attribute passthrough', function () {
    config()->set('bridge.shell.view', 'shell-components');

    $this->html('/customers')
        ->assertOk()
        ->assertSee('<meta name="bridge-protocol" content="1">', false)
        ->assertSee('<meta name="bridge-build" content="test-build">', false)
        ->assertSee('<script type="application/json" id="bridge-page">', false)
        ->assertSee('<div id="app" data-bridge class="h-full" data-theme="dark"></div>', false)
        ->assertBridgePage('Customers/Index', fn ($page) => $page->where('n', 1)->build('test-build'));
});

it('accepts explicit page, build and protocol overrides and renders empty roots', function () {
    config()->set('bridge.shell.view', 'shell-components-custom');

    $response = $this->html('/customers')->assertOk()
        ->assertSee('<meta name="bridge-protocol" content="7">', false)
        ->assertSee('<meta name="bridge-build" content="custom">', false)
        ->assertSee('<div id="root" data-bridge></div>', false)
        ->assertSee('<div id="second" data-bridge></div>', false);

    // The explicit page is embedded with hex-encoded angle brackets, exactly like the directive.
    $content = (string) $response->getContent();
    expect(substr_count($content, 'id="bridge-page"'))->toBe(1)
        ->and($content)->toContain('"component":"Custom"')
        ->and($content)->toContain('\u003Cb\u003E')
        ->and(str_contains($content, '<b>'))->toBeFalse();
});

it('embeds server-rendered output when ssr is enabled', function () {
    config()->set('bridge.shell.view', 'shell-components');
    config()->set('bridge.ssr.enabled', true);
    $this->app->instance(SsrGateway::class, new class implements SsrGateway
    {
        public function render(array $page): ?SsrResult
        {
            return new SsrResult(['<title>SSR</title>'], '<main>rendered</main>');
        }
    });

    $this->html('/customers')
        ->assertSee('<title>SSR</title>', false)
        ->assertSee('<div id="app" data-bridge class="h-full" data-theme="dark" data-server-rendered="true"><main>rendered</main></div>', false);
});

it('renders an empty root outside a bridge response', function () {
    Route::get('/plain', fn () => view('shell-components'));

    $this->get('/plain')
        ->assertOk()
        ->assertDontSee('id="bridge-page"', false)
        ->assertSee('<meta name="bridge-protocol" content="1">', false)
        ->assertSee('<div id="app" data-bridge class="h-full" data-theme="dark"></div>', false);
});
