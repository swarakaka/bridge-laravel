<?php

declare(strict_types=1);

use Bridge\Bridge;
use Bridge\BridgeManager;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config()->set('bridge.shell.view', 'shell');

    Route::middleware('web')->get('/plain', fn () => Bridge::render('Plain'));
    Route::middleware('web')->get('/login', fn () => Bridge::render('Auth/Login'));
});

it('adds no history members by default', function () {
    $this->page('/plain')->assertOk()->assertJsonMissingPath('meta');
});

it('encrypts every page when configured, in page mode and the embedded shell only', function () {
    config()->set('bridge.history.encrypt', true);

    $this->page('/plain')->assertJsonPath('meta.encryptHistory', true);
    $this->html('/plain')->assertBridgePage('Plain', function ($page) {
        expect($page->toArray()['meta'])->toBe(['encryptHistory' => true]);
    });
    $this->json_mode('GET', '/plain')->assertOk()->assertJsonMissingPath('meta');
});

it('lets a response, the request and route middleware decide', function () {
    config()->set('bridge.history.encrypt', true);

    Route::middleware('web')->get('/opt-out', fn () => Bridge::render('Plain')->encryptHistory(false));
    Route::middleware('web')->get('/request', function () {
        Bridge::encryptHistory();

        return Bridge::render('Plain');
    });
    Route::middleware(['web', 'bridge.encrypt-history:false'])->get('/route-off', fn () => Bridge::render('Plain'));

    $this->page('/opt-out')->assertJsonMissingPath('meta');
    $this->page('/route-off')->assertJsonMissingPath('meta');

    config()->set('bridge.history.encrypt', false);

    Route::middleware(['web', 'bridge.encrypt-history'])->get('/route-on', fn () => Bridge::render('Plain'));

    $this->page('/request')->assertJsonPath('meta.encryptHistory', true);
    $this->page('/route-on')->assertJsonPath('meta.encryptHistory', true);
});

it('sends clearHistory on the page that asked for it and does not carry it further', function () {
    Route::middleware('web')->get('/clear', function () {
        Bridge::clearHistory();

        return Bridge::render('Plain');
    });

    $this->page('/clear')->assertJsonPath('meta.clearHistory', true);
    $this->page('/plain')->assertJsonMissingPath('meta');
});

it('carries clearHistory across a redirect that invalidated the session', function (string $accept) {
    Route::middleware('web')->post('/logout', function (Request $request) {
        Bridge::clearHistory();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Bridge::redirect()->to('/login');
    });

    $this->withoutMiddleware(ValidateCsrfToken::class)
        ->withHeaders(['Accept' => $accept])
        ->post('/logout')
        ->assertRedirect('/login')
        ->assertSessionHas(BridgeManager::CLEAR_HISTORY_SESSION_KEY, true);

    // A JSON call in between neither shows nor consumes it.
    $this->json_mode('GET', '/plain')->assertJsonMissingPath('meta');

    $this->page('/login')->assertJsonPath('meta.clearHistory', true);
    $this->page('/login')->assertJsonMissingPath('meta');
})->with([
    'page mode' => ['application/vnd.bridge+json; v=1'],
    'html form post' => ['text/html'],
]);

it('reaches the next embedded page after an HTML redirect', function () {
    Route::middleware('web')->get('/leave', function () {
        Bridge::clearHistory();

        return redirect('/login');
    });

    $this->html('/leave')->assertRedirect('/login');
    $this->html('/login')->assertBridgePage('Auth/Login', function ($page) {
        expect($page->toArray()['meta'])->toBe(['clearHistory' => true]);
    });
});

it('clears history on logout unless disabled', function (bool $enabled) {
    config()->set('bridge.history.clear_on_logout', $enabled);

    Route::middleware('web')->get('/sign-out', function () {
        event(new Logout('web', new GenericUser(['id' => 1])));

        return Bridge::render('Auth/Login');
    });

    $response = $this->page('/sign-out');

    $enabled
        ? $response->assertJsonPath('meta.clearHistory', true)
        : $response->assertJsonMissingPath('meta');
})->with(['enabled' => true, 'disabled' => false]);

it('ignores clearHistory for JSON requests', function () {
    Route::middleware('web')->post('/api-logout', function () {
        Bridge::clearHistory();

        return Bridge::redirect()->to('/login');
    });

    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->json_mode('POST', '/api-logout')->assertSessionMissing(BridgeManager::CLEAR_HISTORY_SESSION_KEY);
});
