<?php

declare(strict_types=1);

use Bridge\Facades\Bridge;
use Bridge\Negotiation\Negotiation;
use Bridge\Tests\Fixtures\Http\LoginResponse;
use Bridge\Tests\Fixtures\Http\ReadsAcceptEarly;
use Bridge\Tests\Fixtures\Http\RedirectsBrowsers;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Laravel code that branches on wantsJson()/expectsJson() (Fortify's responses,
// EnsureEmailIsVerified, RequirePassword, Authenticate) must treat a Page visit
// like a browser visit, not an API call.

beforeEach(function () {
    config()->set('bridge.shell.view', 'shell');

    Route::middleware('web')->post('/login', fn () => new LoginResponse);
    Route::middleware('web')->post('/two-factor-challenge', fn (Request $request) => $request->wantsJson()
        ? response()->noContent()
        : redirect('/dashboard'));
    Route::middleware('web')->get('/expects', fn (Request $request) => $request->expectsJson()
        ? response()->json(['expects' => true])
        : redirect('/dashboard'));
    Route::middleware('web')->get('/accept', fn (Request $request) => response()->json([
        'accept' => $request->header('Accept'),
        'server' => $request->server('HTTP_ACCEPT'),
        'original' => $request->attributes->get(Negotiation::ORIGINAL_ACCEPT_ATTRIBUTE),
        'wants_json' => $request->wantsJson(),
    ]));
    Route::middleware('web')->get('/dashboard', fn (Request $request) => Bridge::render('Dashboard', [
        'wants_json' => $request->wantsJson(),
    ]));
});

it('redirects Fortify-style responses in page mode instead of returning JSON', function () {
    $this->withHeaders(['Accept' => $this::PAGE_ACCEPT, 'X-Requested-With' => 'XMLHttpRequest'])
        ->post('/login')
        ->assertStatus(303)
        ->assertHeader('Location', 'http://localhost/dashboard');

    $this->withHeaders(['Accept' => $this::PAGE_ACCEPT])
        ->post('/two-factor-challenge')
        ->assertStatus(303)
        ->assertHeader('Location', 'http://localhost/dashboard');
});

it('makes expectsJson() false for page visits sent with X-Requested-With', function () {
    $this->page('/expects', ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertStatus(303)
        ->assertHeader('Location', 'http://localhost/dashboard');
});

it('keeps the JSON branch in JSON mode', function () {
    $this->json_mode('POST', '/login')->assertOk()->assertExactJson(['two_factor' => false]);
    $this->json_mode('POST', '/two-factor-challenge')->assertNoContent();
    $this->json_mode('GET', '/expects')->assertOk()->assertExactJson(['expects' => true]);
});

it('keeps the redirect branch in HTML mode', function () {
    $this->withHeaders(['Accept' => 'text/html'])->post('/login')->assertStatus(302)->assertRedirect('/dashboard');
});

it('still renders page mode after the rewrite and keeps the original Accept on the request', function () {
    $this->page('/dashboard')->assertBridgePage('Dashboard', fn ($page) => $page->where('wants_json', false));

    $this->page('/accept')->assertOk()->assertJson([
        'accept' => 'text/html, application/xhtml+xml',
        'server' => 'text/html, application/xhtml+xml',
        'original' => $this::PAGE_ACCEPT,
        'wants_json' => false,
    ]);
});

it('ignores Accept types cached by middleware that ran before negotiation', function () {
    app(Kernel::class)->prependMiddlewareToGroup('web', ReadsAcceptEarly::class);

    $this->withHeaders(['Accept' => $this::PAGE_ACCEPT])->post('/login')->assertStatus(303);
});

it('leaves JSON and stream requests untouched', function (string $accept, bool $wantsJson) {
    $this->withHeaders(['Accept' => $accept])->get('/accept')->assertJson([
        'accept' => $accept,
        'server' => $accept,
        'original' => null,
        'wants_json' => $wantsJson,
    ]);
})->with([
    'json' => ['application/json', true],
    'stream' => ['text/event-stream', false],
]);

it('lets the auth middleware see a browser visit and answers with a 401 redirect hint', function () {
    // `auth` is a priority middleware and runs before HandleBridgeRequests, so it still
    // sees the page Accept. The hint comes from AuthenticationException::redirectTo()
    // (Laravel's redirectGuestsTo, route('login') by default) in ErrorMapper instead.
    Route::middleware('web')->get('/sign-in', fn () => Bridge::render('Auth/Login'))->name('login');
    Route::middleware(['web', 'auth'])->get('/account', fn () => Bridge::render('Account'));

    $this->page('/account', ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertBridgeError(401, 'unauthenticated')
        ->assertJsonPath('error.redirect', 'http://localhost/sign-in');

    $this->json_mode('GET', '/account')->assertStatus(401)->assertExactJson(['message' => 'Unauthenticated.']);
    $this->withHeaders(['Accept' => 'text/html'])->get('/account')->assertRedirect('/sign-in');
});

it('answers a 401 with the configured hint when the app has no login route', function () {
    config()->set('bridge.auth.login_url', '/signin');
    Route::middleware(['web', 'auth'])->get('/account', fn () => Bridge::render('Account'));

    $this->page('/account')->assertBridgeError(401, 'unauthenticated')->assertJsonPath('error.redirect', '/signin');
});

it('lets route middleware outside the priority list see a browser visit', function () {
    Route::middleware(['web', 'expects-json-probe'])->get('/probe', fn () => Bridge::render('Probe'));
    app('router')->aliasMiddleware('expects-json-probe', RedirectsBrowsers::class);

    $this->page('/probe', ['X-Requested-With' => 'XMLHttpRequest'])->assertStatus(303)->assertHeader('Location', 'http://localhost/dashboard');
    $this->json_mode('GET', '/probe')->assertStatus(403);
});
