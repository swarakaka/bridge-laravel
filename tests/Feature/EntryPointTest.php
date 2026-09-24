<?php

declare(strict_types=1);

use Bridge\Bridge;
use Bridge\BridgeManager;
use Bridge\Facades\Bridge as DeprecatedBridge;
use Bridge\Http\Responses\PageResponse;
use Bridge\Testing\AssertablePage;
use Illuminate\Support\Facades\Route;

it('renders through Bridge\Bridge statically', function () {
    Route::get('/static', fn () => Bridge::render('Welcome', ['name' => 'Ada']))->middleware('web');

    expect(Bridge::render('Welcome'))->toBeInstanceOf(PageResponse::class);

    $this->page('/static')
        ->assertOk()
        ->assertBridgePage('Welcome', fn (AssertablePage $page) => $page->where('name', 'Ada'))
        ->assertBridgeProp('name', 'Ada');
});

it('keeps the deprecated Bridge\Facades\Bridge working', function () {
    Route::get('/deprecated', fn () => DeprecatedBridge::render('Welcome', ['name' => 'Ada']))->middleware('web');

    DeprecatedBridge::share('app', 'Bridge');

    expect(DeprecatedBridge::getFacadeRoot())->toBe(Bridge::getFacadeRoot())
        ->and(Bridge::shared('app'))->toBe('Bridge');

    $this->page('/deprecated')
        ->assertOk()
        ->assertBridgePage('Welcome')
        ->assertBridgeProp('app', 'Bridge');
});

it('resolves the facade root, the class and the bridge alias to one singleton', function () {
    $manager = app(BridgeManager::class);

    expect(Bridge::getFacadeRoot())->toBe($manager)
        ->and(app('bridge'))->toBe($manager)
        ->and(app(BridgeManager::class))->toBe($manager);
});

it('keeps the testing helpers working for every mode', function () {
    Route::get('/helpers', fn () => Bridge::render('Customers/Index', ['customers' => [1, 2]]))->middleware('web');

    $this->page('/helpers')->assertBridgePage('Customers/Index', fn (AssertablePage $page) => $page->has('customers', 2));
    $this->json_mode('GET', '/helpers')->assertJsonMode();
    $this->html('/helpers')->assertHtmlShell();
});
