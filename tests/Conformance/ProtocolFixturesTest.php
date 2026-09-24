<?php

declare(strict_types=1);

use Bridge\Bridge;
use Bridge\Support\Headers;
use Bridge\Tests\Fixtures\Http\CustomerResource;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

const PROTOCOL_DIR = __DIR__.'/../../../protocol';

function protocolFixture(string $path): array
{
    return json_decode((string) file_get_contents(PROTOCOL_DIR.'/fixtures/'.$path), true, 512, JSON_THROW_ON_ERROR);
}

function validateAgainst(string $schema, string $json): void
{
    $validator = new Validator;
    $resolver = $validator->resolver();

    foreach (['page', 'error', 'json', 'json-error', 'stream-control'] as $name) {
        $resolver->registerFile("https://bridge.swarakaka.dev/schemas/v1/{$name}.schema.json", PROTOCOL_DIR."/schemas/{$name}.schema.json");
    }

    $result = $validator->validate(json_decode($json, false, 512, JSON_THROW_ON_ERROR), "https://bridge.swarakaka.dev/schemas/v1/{$schema}.schema.json");

    expect($result->isValid())->toBeTrue($result->error() ? json_encode((new ErrorFormatter)->format($result->error())) : 'valid');
}

function customersPaginator(array $rows, int $page = 2): LengthAwarePaginator
{
    return new LengthAwarePaginator($rows, total: 57, perPage: 20, currentPage: $page, options: ['path' => '/customers']);
}

beforeEach(function () {
    config()->set('bridge.shell.view', 'shell');
    Bridge::flushShared();
    Bridge::setVersion('3f9c1a');
    Bridge::share('auth', ['user' => ['id' => 1, 'name' => 'Ada']]);

    $rows = [
        ['id' => 21, 'name' => 'Acme', 'email' => 'hello@acme.test'],
        ['id' => 22, 'name' => 'Globex', 'email' => 'info@globex.test'],
    ];

    Route::middleware('web')->get('/customers', fn () => Bridge::render('Customers/Index', [
        'customers' => CustomerResource::collection(customersPaginator($rows)),
        'filters' => ['search' => null],
    ]));

    Route::middleware('web')->get('/customers/{id}', fn () => Bridge::render('Customers/Show', [
        'customer' => CustomerResource::make($rows[0]),
    ]))->whereNumber('id');

    Route::middleware('web')->get('/', fn () => Bridge::render('Dashboard', [
        'recentCustomers' => [['id' => 22, 'name' => 'Globex']],
        'stats' => Bridge::defer(fn () => ['x' => 1]),
        'revenue' => Bridge::defer(fn () => [], 'charts'),
        'signups' => Bridge::defer(fn () => [], 'charts'),
    ]));

    Route::middleware('web')->post('/customers', function (Request $request) {
        $request->validate(['email' => 'required|email', 'name' => 'required|min:2']);

        return Bridge::redirect()->to('/customers/23')
            ->with('customer', CustomerResource::make(['id' => 23, 'name' => 'Initech', 'email' => 'it@initech.test']))
            ->flash('Customer created.')
            ->created();
    });

    Route::middleware('web')->delete('/customers/{id}', fn () => Bridge::redirect()->to('/customers'));
    Route::middleware('web')->get('/secret', fn () => throw new AuthenticationException);
    Route::middleware('web')->get('/forbidden', fn () => abort(403));
    Route::middleware('web')->get('/missing', fn () => abort(404));
    Route::middleware('web')->get('/csrf', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
    Route::middleware('web')->get('/throttled', fn () => throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => 30]));
    Route::middleware('web')->get('/boom', fn () => throw new RuntimeException('boom'));
});

it('produces the page fixtures byte-for-byte (as decoded JSON)', function (string $fixture, string $uri, array $headers) {
    $response = $this->page($uri, $headers)->assertOk();

    validateAgainst('page', (string) $response->getContent());
    expect($response->json())->toEqual(protocolFixture("page/{$fixture}"));
})->with([
    'full' => ['full.json', '/customers?page=2', []],
    'partial' => ['partial.json', '/customers?page=2', [Headers::ONLY => 'customers', Headers::COMPONENT => 'Customers/Index']],
    'deferred' => ['deferred.json', '/', []],
]);

it('produces the minimal page fixture', function () {
    Bridge::flushShared();
    Bridge::setVersion(null);
    Route::middleware('web')->get('/welcome', fn () => Bridge::render('Welcome'));

    $response = $this->page('/welcome')->assertOk();
    $expected = protocolFixture('page/minimal.json');
    $expected['url'] = '/welcome';

    validateAgainst('page', (string) $response->getContent());
    expect($response->json())->toEqual($expected);
});

it('produces the history fixtures', function () {
    config()->set('bridge.history.encrypt', true);

    $encrypted = $this->page('/customers/21')->assertOk();
    validateAgainst('page', (string) $encrypted->getContent());
    expect($encrypted->json())->toEqual(protocolFixture('page/encrypt-history.json'));

    config()->set('bridge.history.encrypt', false);
    Route::middleware('web')->get('/login', fn () => Bridge::render('Auth/Login'));
    Route::middleware('web')->post('/logout', function (Request $request) {
        Bridge::clearHistory();
        $request->session()->invalidate();

        return Bridge::redirect()->to('/login');
    });

    $this->withoutMiddleware(ValidateCsrfToken::class)
        ->withHeaders(['Accept' => $this::PAGE_ACCEPT])->post('/logout')->assertStatus(303);

    // The handled request restored the boot-time shares; the fixture has none.
    Bridge::flushShared();
    $cleared = $this->page('/login')->assertOk();
    validateAgainst('page', (string) $cleared->getContent());
    expect($cleared->json())->toEqual(protocolFixture('page/clear-history.json'));
});

it('produces the deferred-once fixture for a client that holds the value', function () {
    Route::middleware('web')->get('/dashboard', fn () => Bridge::render('Dashboard', [
        'recentCustomers' => [['id' => 22, 'name' => 'Globex']],
        'stats' => Bridge::defer(fn () => ['x' => 1]),
        'signups' => Bridge::defer(fn () => [], 'charts')->once(),
    ]));

    $response = $this->page('/dashboard', [Headers::ONCE => 'signups'])->assertOk();

    validateAgainst('page', (string) $response->getContent());
    expect($response->json())->toEqual(protocolFixture('page/deferred-once-held.json'));
});

it('produces the merge-modes fixture', function () {
    Route::middleware('web')->get('/feed', fn () => Bridge::render('Feed', [
        'customers' => Bridge::merge(['data' => [['id' => 22, 'name' => 'Globex']], 'meta' => ['current_page' => 2]])->matchOn('data.id'),
        'messages' => Bridge::merge([['id' => 7, 'body' => 'Earlier']])->prepend()->matchOn('id'),
        'settings' => Bridge::deepMerge(['theme' => ['dark' => true]]),
    ]));

    $response = $this->page('/feed?page=2')->assertOk();

    validateAgainst('page', (string) $response->getContent());
    expect($response->json())->toEqual(protocolFixture('page/merge-modes.json'));
});

it('produces the once fixtures', function (string $fixture, array $headers) {
    Route::middleware('web')->get('/customers/create', fn () => Bridge::render('Customers/Create', [
        'statuses' => Bridge::once(fn () => ['active', 'inactive'], key: 'customer-statuses'),
    ]));

    $response = $this->page('/customers/create', $headers)->assertOk();

    validateAgainst('page', (string) $response->getContent());
    expect($response->json())->toEqual(protocolFixture("page/{$fixture}"));
})->with([
    'full' => ['once-full.json', []],
    'held' => ['once-held.json', [Headers::ONCE => 'customer-statuses, other']],
]);

it('embeds the same page object in the HTML shell', function () {
    $this->html('/customers?page=2')->assertBridgePage('Customers/Index', function ($page) {
        expect($page->toArray())->toEqual(protocolFixture('page/full.json'));
    });
});

it('produces the page-mode error fixtures', function (string $fixture, string $method, string $uri, array $data) {
    $response = $this->call($method, $uri, $data, [], [], $this->transformHeadersToServerVars(['Accept' => $this::PAGE_ACCEPT]));

    validateAgainst('error', (string) $response->getContent());
    expect($response->json())->toEqual(protocolFixture("error/{$fixture}"));
})->with([
    'validation' => ['validation.json', 'POST', '/customers', ['name' => 'a']],
    'unauthenticated' => ['unauthenticated.json', 'GET', '/secret', []],
    'forbidden' => ['forbidden.json', 'GET', '/forbidden', []],
    'not-found' => ['not-found.json', 'GET', '/missing', []],
    'csrf' => ['csrf.json', 'GET', '/csrf', []],
    'throttled' => ['throttled.json', 'GET', '/throttled', []],
    'server' => ['server.json', 'GET', '/boom', []],
]);

it('produces the JSON-mode fixtures', function (string $fixture, string $schema, string $method, string $uri, array $data) {
    Bridge::flushShared();
    $response = $this->json_mode($method, $uri, $data);

    validateAgainst($schema, (string) $response->getContent());
    expect($response->json())->toEqual(protocolFixture("json/{$fixture}"));
})->with([
    'collection' => ['collection.json', 'json', 'GET', '/customers?page=2', []],
    'single' => ['single.json', 'json', 'GET', '/customers/21', []],
    'created' => ['created.json', 'json', 'POST', '/customers', ['name' => 'Initech', 'email' => 'it@initech.test']],
    'redirect-empty' => ['redirect-empty.json', 'json', 'DELETE', '/customers/21', []],
    'error-validation' => ['error-validation.json', 'json-error', 'POST', '/customers', ['name' => 'a']],
    'error-unauthenticated' => ['error-unauthenticated.json', 'json-error', 'GET', '/secret', []],
]);
