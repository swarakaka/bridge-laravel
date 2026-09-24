<?php

declare(strict_types=1);

use Bridge\Bridge;
use Bridge\Support\Headers;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config()->set('bridge.shell.view', 'shell');
    Bridge::flushShared();

    Route::middleware('web')->get('/feed', fn () => Bridge::render('Feed', [
        'items' => Bridge::merge(fn () => [['id' => (int) request('page', 1)]]),
        'total' => 2,
    ]));

    Route::middleware('web')->get('/customers/{id}', fn (int $id) => Bridge::render('Customers/Show', [
        'customer' => ['id' => $id, 'name' => 'Acme'],
        'related' => [1, 2],
    ])->jsonRoot('customer'));
});

it('lists merge props under meta.merge in page mode', function () {
    $this->page('/feed')->assertBridgePage('Feed', fn ($page) => $page->where('items', [['id' => 1]]));
    expect($this->page('/feed')->json('meta.merge'))->toBe(['items']);
    expect($this->page('/feed?page=2', [Headers::ONLY => 'items', Headers::COMPONENT => 'Feed'])->json())
        ->toMatchArray(['props' => ['items' => [['id' => 2]]], 'meta' => ['merge' => ['items']]]);
});

it('omits merge metadata from JSON mode', function () {
    expect($this->json_mode('GET', '/feed')->json())->toBe(['data' => ['items' => [['id' => 1]], 'total' => 2]]);
});

it('makes a prop the JSON root and moves the rest to meta', function () {
    $this->json_mode('GET', '/customers/5')->assertExactJson([
        'data' => ['id' => 5, 'name' => 'Acme'],
        'meta' => ['related' => [1, 2]],
    ]);

    $this->page('/customers/5')->assertBridgePage('Customers/Show', fn ($page) => $page->where('customer.id', 5)->where('related', [1, 2]));
});
