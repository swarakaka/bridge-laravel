<?php

declare(strict_types=1);

use Bridge\Facades\Bridge;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config()->set('bridge.shell.view', 'shell');
    config()->set('bridge.auth.login_url', '/login');

    Route::middleware('web')->post('/customers', function (Request $request) {
        $request->validate(['email' => 'required|email', 'name' => 'required|min:2']);

        return Bridge::redirect()->to('/customers');
    });

    Route::middleware('web')->get('/secret', fn () => throw new AuthenticationException);
    Route::middleware('web')->get('/forbidden', fn () => abort(403));
    Route::middleware('web')->get('/missing', fn () => abort(404));
    Route::middleware('web')->get('/boom', fn () => throw new RuntimeException('boom'));
    Route::middleware('web')->get('/customers', fn () => Bridge::render('Customers/Index'));
});

it('returns 422 error objects in page mode without redirecting', function () {
    $this->withHeaders(['Accept' => $this::PAGE_ACCEPT])->post('/customers', ['name' => 'a'])
        ->assertBridgeError(422, 'validation')
        ->assertHeader('Content-Type', 'application/vnd.bridge+json; v=1')
        ->assertJsonPath('error.errors.email.0', 'The email field is required.')
        ->assertJsonPath('error.errors.name.0', 'The name field must be at least 2 characters.')
        ->assertJson(['protocol' => 1, 'type' => 'error']);
});

it('returns laravel-native 422 bodies in JSON mode', function () {
    $this->json_mode('POST', '/customers', ['name' => 'a'])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonStructure(['message', 'errors' => ['email', 'name']])
        ->assertJsonMissing(['type' => 'error']);
});

it('keeps laravel redirect-back behaviour in html mode', function () {
    $this->withHeaders(['Accept' => 'text/html'])->from('/customers/create')->post('/customers', [])
        ->assertStatus(302)
        ->assertRedirect('/customers/create')
        ->assertSessionHasErrors(['email', 'name']);

    $this->html('/customers')->assertBridgePage('Customers/Index', fn ($page) => $page->has('errors.email'));
});

it('maps unauthenticated to 401 with a redirect hint', function () {
    $this->page('/secret')->assertBridgeError(401, 'unauthenticated')->assertJsonPath('error.redirect', '/login');
    $this->json_mode('GET', '/secret')->assertStatus(401)->assertExactJson(['message' => 'Unauthenticated.']);
});

it('maps forbidden, not found and server errors in both modes', function () {
    $this->page('/forbidden')->assertBridgeError(403, 'forbidden');
    $this->page('/missing')->assertBridgeError(404, 'not_found')->assertJsonPath('error.message', 'Not Found.');
    $this->page('/boom')->assertBridgeError(500, 'server')->assertJsonPath('error.message', 'Server Error.');

    $this->json_mode('GET', '/forbidden')->assertStatus(403)->assertJsonStructure(['message']);
    $this->json_mode('GET', '/missing')->assertStatus(404)->assertExactJson(['message' => 'Not Found.']);
    $this->json_mode('GET', '/boom')->assertStatus(500)->assertExactJson(['message' => 'Server Error.']);
});

it('includes debug details for JSON server errors only when debugging', function () {
    config()->set('app.debug', true);

    $this->json_mode('GET', '/boom')->assertStatus(500)->assertJsonPath('message', 'boom')->assertJsonStructure(['exception', 'file', 'line', 'trace']);
    $this->page('/boom')->assertBridgeError(500, 'server')->assertJsonMissingPath('error.trace');
});

it('answers 404 for unknown routes in page and JSON mode', function () {
    $this->page('/nope')->assertBridgeError(404, 'not_found');
    $this->json_mode('GET', '/nope')->assertStatus(404)->assertJsonStructure(['message']);
});

it('marks error responses as uncacheable', function () {
    $this->page('/missing')->assertHeader('Cache-Control', 'no-store, private');
});
