<?php

declare(strict_types=1);

use Bridge\Facades\Bridge;
use Bridge\Support\Headers;
use Bridge\Tests\Fixtures\Http\CustomerResource;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\RedirectResponse;

beforeEach(function () {
    config()->set('bridge.shell.view', 'shell');

    Route::middleware('web')->name('customers.show')->get('/customers/{id}', fn (int $id) => Bridge::render('Customers/Show', ['id' => $id]));

    Route::middleware('web')->post('/customers', fn () => Bridge::redirect()
        ->route('customers.show', 23)
        ->with('customer', CustomerResource::make(['id' => 23, 'name' => 'Initech', 'email' => 'it@initech.test']))
        ->flash('Customer created.')
        ->created());

    Route::middleware('web')->post('/plain', fn () => redirect('/customers/1'));
    Route::middleware('web')->post('/external', fn () => redirect('https://example.com/elsewhere'));
    Route::middleware('web')->post('/bridge-external', fn () => Bridge::redirect()->to('https://example.com/x')->external());
});

it('redirects with 303 in page mode and flashes for the next page', function () {
    $response = $this->withHeaders(['Accept' => $this::PAGE_ACCEPT])->post('/customers');

    $response->assertStatus(303)->assertHeader('Location', 'http://localhost/customers/23');

    $this->page('/customers/23')->assertBridgePage('Customers/Show', fn ($page) => $page
        ->where('flash', ['message' => 'Customer created.', 'level' => 'success']));
});

it('returns a result document with Location in JSON mode', function () {
    $this->json_mode('POST', '/customers')
        ->assertStatus(201)
        ->assertHeader('Location', 'http://localhost/customers/23')
        ->assertExactJson([
            'data' => ['customer' => ['id' => 23, 'name' => 'Initech', 'email' => 'it@initech.test']],
            'meta' => ['location' => 'http://localhost/customers/23', 'flash' => ['message' => 'Customer created.', 'level' => 'success']],
        ]);
});

it('redirects with 302 in html mode', function () {
    $this->withHeaders(['Accept' => 'text/html'])->post('/customers')->assertStatus(302)->assertRedirect('/customers/23');
});

it('converts plain laravel redirects for page requests', function () {
    $this->withHeaders(['Accept' => $this::PAGE_ACCEPT])->post('/plain')->assertStatus(303);
    $this->withHeaders(['Accept' => 'text/html'])->post('/plain')->assertStatus(302);
});

it('represents external redirects as 409 with X-Bridge-Location', function (string $uri) {
    $this->withHeaders(['Accept' => $this::PAGE_ACCEPT])->post($uri)
        ->assertStatus(409)
        ->assertHeader(Headers::LOCATION);
})->with(['/external', '/bridge-external']);

it('treats urls that browsers resolve to another host as external', function (string $target) {
    Route::middleware('web')->post('/tricky', fn () => new RedirectResponse($target));

    $this->withHeaders(['Accept' => $this::PAGE_ACCEPT])->post('/tricky')
        ->assertStatus(409)
        ->assertHeader(Headers::LOCATION, $target);
})->with(['/\\evil.com', '///evil.com', "/\t/evil.com", '\\\\evil.com', 'https:evil.com', '//evil.com']);

it('keeps same-origin relative and absolute targets as 303', function (string $target) {
    Route::middleware('web')->post('/local', fn () => new RedirectResponse($target));

    $this->withHeaders(['Accept' => $this::PAGE_ACCEPT])->post('/local')->assertStatus(303);
})->with(['/customers/1', '/customers?q=a%2Fb', 'http://localhost/customers/1']);

it('returns null data when nothing is attached', function () {
    Route::middleware('web')->delete('/customers/{id}', fn () => Bridge::redirect()->to('/customers'));

    $this->json_mode('DELETE', '/customers/1')
        ->assertOk()
        ->assertHeader('Location', '/customers')
        ->assertExactJson(['data' => null, 'meta' => ['location' => '/customers']]);
});
