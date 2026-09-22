<?php

declare(strict_types=1);

use Bridge\Http\Middleware\VerifyCsrfToken;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // VerifyCsrfToken is a no-op while the app env is "testing".
    $this->app['env'] = 'local';

    Route::middleware([EncryptCookies::class, StartSession::class, VerifyCsrfToken::class])
        ->post('/mutate', fn () => response()->json(['ok' => true]));
});

it('enforces csrf for cookie sessions', function () {
    $this->withHeaders(['Accept' => 'application/json'])->post('/mutate')->assertStatus(419);
});

it('skips csrf for bearer requests without a session cookie', function () {
    $this->withHeaders(['Accept' => 'application/json', 'Authorization' => 'Bearer token'])
        ->post('/mutate')
        ->assertOk();
});

it('still enforces csrf when both bearer and session cookie are present', function () {
    $this->withHeaders(['Accept' => 'application/json', 'Authorization' => 'Bearer token'])
        ->withUnencryptedCookie((string) config('session.cookie'), 'session-id')
        ->post('/mutate')
        ->assertStatus(419);
});

it('can be disabled via config', function () {
    config()->set('bridge.csrf.skip_for_bearer', false);

    $this->withHeaders(['Accept' => 'application/json', 'Authorization' => 'Bearer token'])
        ->post('/mutate')
        ->assertStatus(419);
});
