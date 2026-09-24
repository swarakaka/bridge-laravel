<?php

declare(strict_types=1);

use Bridge\Bridge;
use Bridge\Support\Headers;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config()->set('bridge.shell.view', 'shell');
    Route::middleware('web')->get('/customers', fn () => Bridge::render('Customers/Index', ['n' => 1]));
    Route::middleware('web')->post('/customers', fn () => Bridge::redirect()->to('/customers'));
});

it('returns 409 with X-Bridge-Location on stale GET builds only', function () {
    $this->page('/customers?page=2', [Headers::BUILD => 'old'])
        ->assertStatus(409)
        ->assertHeader(Headers::LOCATION, 'http://localhost/customers?page=2');

    $this->page('/customers', [Headers::BUILD => 'test-build'])->assertOk();
    $this->page('/customers')->assertOk();
    $this->withHeaders(['Accept' => $this::PAGE_ACCEPT, Headers::BUILD => 'old'])->post('/customers')->assertStatus(303);
    $this->json_mode('GET', '/customers', [], [Headers::BUILD => 'old'])->assertOk();
});

it('derives the build from a closure or the manifest', function () {
    Bridge::setVersion(fn () => 'closure-build');
    $this->page('/customers')->assertBridgePage(null, fn ($page) => $page->build('closure-build'));

    Bridge::setVersion(null);
    expect(Bridge::version())->toBeNull();
});

it('adds weak etags and answers 304 on match for page and json GETs', function () {
    $first = $this->page('/customers')->assertOk();
    $etag = $first->headers->get('ETag');

    expect($etag)->toStartWith('W/"');

    $this->page('/customers', ['If-None-Match' => $etag])->assertStatus(304);
    $this->json_mode('GET', '/customers')->assertOk()->assertHeader('ETag');
    $this->html('/customers')->assertHeaderMissing('ETag');
});

it('disables etags via config', function () {
    config()->set('bridge.cache.etag', false);
    $this->page('/customers')->assertHeaderMissing('ETag');
});

it('honours explicit cache options and refuses public for authenticated users', function () {
    Route::middleware('web')->get('/public', fn () => Bridge::render('Public', [])->cache(maxAge: 60, public: true));
    Route::middleware('web')->get('/forced', fn () => Bridge::render('Public', [])->cache(maxAge: 60, public: true, force: true));

    $this->page('/public')->assertHeader('Cache-Control', 'max-age=60, must-revalidate, public');

    $user = new class extends User
    {
        protected $table = 'users';
    };
    $user->id = 1;

    $this->actingAs($user)->page('/public')->assertHeader('Cache-Control', 'max-age=60, must-revalidate, private');
    $this->actingAs($user)->page('/forced')->assertHeader('Cache-Control', 'max-age=60, must-revalidate, public');
});

it('uses no-store for embedded shells of authenticated users', function () {
    $user = new class extends User
    {
        protected $table = 'users';
    };
    $user->id = 1;

    $this->actingAs($user)->html('/customers')->assertHeader('Cache-Control', 'no-store, private');
});
