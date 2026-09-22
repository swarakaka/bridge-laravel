<?php

declare(strict_types=1);

use Bridge\Facades\Bridge;
use Bridge\Support\Headers;
use Bridge\Tests\Fixtures\Http\CustomerResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config()->set('bridge.shell.view', 'shell');

    Route::middleware('web')->get('/customers', function () {
        $paginator = new LengthAwarePaginator(
            [['id' => 21, 'name' => 'Acme', 'email' => 'hello@acme.test']],
            total: 57,
            perPage: 20,
            currentPage: 2,
            options: ['path' => '/customers'],
        );

        return Bridge::render('Customers/Index', [
            'customers' => CustomerResource::collection($paginator),
            'filters' => ['search' => null],
        ]);
    });
});

it('serves the HTML shell with the page embedded', function () {
    $response = $this->html('/customers?page=2');

    $response->assertOk()
        ->assertHtmlShell()
        ->assertHeader('Vary', 'Accept')
        ->assertHeader('Cache-Control', 'no-cache, private')
        ->assertSee('<meta name="bridge-protocol" content="1">', false)
        ->assertSee('<meta name="bridge-build" content="test-build">', false)
        ->assertSee('<div id="app" data-bridge></div>', false)
        ->assertBridgePage('Customers/Index', fn ($page) => $page
            ->url('/customers?page=2')
            ->build('test-build')
            ->where('customers.meta.total', 57)
            ->where('filters.search', null));
});

it('serves the page object for bridge requests', function () {
    $response = $this->page('/customers?page=2');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.bridge+json; v=1')
        ->assertHeader('Vary', Headers::VARY)
        ->assertHeader('Cache-Control', 'no-cache, private')
        ->assertBridgePage('Customers/Index', fn ($page) => $page
            ->url('/customers?page=2')
            ->has('customers.data', 1)
            ->where('customers.data.0.name', 'Acme')
            ->where('customers.links.next', '/customers?page=3')
            ->where('customers.meta.current_page', 2))
        ->assertJson(['protocol' => 1, 'type' => 'page']);

    expect($response->json())->toHaveKeys(['protocol', 'type', 'component', 'url', 'props', 'build'])
        ->and($response->json())->not->toHaveKey('deferred');
});

it('serves the JSON envelope for JSON requests', function () {
    $response = $this->json_mode('GET', '/customers?page=2');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('Vary', Headers::VARY)
        ->assertJsonMode()
        ->assertJsonPath('data.customers.meta.total', 57)
        ->assertJsonPath('data.customers.data.0.email', 'hello@acme.test')
        ->assertJsonPath('data.filters.search', null);

    expect($response->json())->not->toHaveKeys(['component', 'url', 'props']);
});

it('uses html for wildcard accepts by default and json when the route says so', function () {
    Route::middleware('web')->get('/api-ish', fn () => Bridge::render('X', ['a' => 1]))
        ->defaults('bridge.default_mode', 'json');

    $this->withHeaders(['Accept' => '*/*'])->get('/customers')->assertHtmlShell();
    $this->withHeaders(['Accept' => '*/*'])->get('/api-ish')->assertJsonMode()->assertJsonPath('data.a', 1);
    $this->get('/api-ish')->assertJsonMode();
});

it('answers 406 for stream accepts on rendering routes', function () {
    $this->withHeaders(['Accept' => 'text/event-stream'])->get('/customers')
        ->assertStatus(406)
        ->assertJson(['message' => 'Not Acceptable'])
        ->assertJsonPath('acceptable.0', 'application/vnd.bridge+json');
});

it('answers 406 for unsupported protocol versions and unacceptable types', function () {
    $this->withHeaders(['Accept' => 'application/vnd.bridge+json; v=2'])->get('/customers')
        ->assertStatus(406)
        ->assertJson(['message' => 'Unsupported Bridge protocol version', 'supported' => [1]]);

    $this->withHeaders(['Accept' => 'image/png'])->get('/customers')->assertStatus(406);
});

it('renders a static shell when embedding is disabled', function () {
    config()->set('bridge.shell.embed', false);

    $this->html('/customers')
        ->assertHtmlShell(embedded: false)
        ->assertHeader('Cache-Control', 'max-age=300, must-revalidate, public');
});

it('supports per-response embed and shell overrides', function () {
    Route::middleware('web')->get('/static', fn () => Bridge::render('X')->embed(false));

    $this->html('/static')->assertHtmlShell(embedded: false);
});

it('exposes the negotiated mode on the request', function () {
    Route::middleware('web')->get('/mode', fn () => response()->json(['mode' => request()->bridgeMode()->value, 'facade' => Bridge::mode()->value]));

    $this->json_mode('GET', '/mode')->assertJson(['mode' => 'json', 'facade' => 'json']);
    $this->page('/mode')->assertJson(['mode' => 'page']);
});
