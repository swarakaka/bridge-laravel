<?php

declare(strict_types=1);

use Bridge\Bridge;
use Bridge\Http\Middleware\HandleBridgeRequests;
use Bridge\Tests\Fixtures\Http\SharesProps;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config()->set('bridge.shell.view', 'shell');

    Route::middleware('web')->get('/dashboard', fn () => Bridge::render('Dashboard', ['app' => 'page wins']));
    Route::middleware('web')->get('/settings', fn () => Bridge::render('Settings'));
    Route::middleware('web')->post('/settings', fn () => redirect('/settings'));
});

it('does not auto-register the package middleware next to an application subclass', function () {
    $web = app(Kernel::class)->getMiddlewareGroups()['web'];

    expect($web)->toContain(SharesProps::class)
        ->not->toContain(HandleBridgeRequests::class);
});

it('shares the props returned by the subclass', function () {
    $this->page('/settings')->assertBridgePage('Settings', fn ($page) => $page
        ->where('app', 'Bridge')
        ->where('path', 'settings'));

    $this->page('/dashboard')->assertBridgePage('Dashboard', fn ($page) => $page
        ->where('app', 'page wins')
        ->where('path', 'dashboard'));
});

it('drops the shares once the request is handled', function () {
    $this->page('/settings')->assertOk();

    expect(Bridge::shared('app'))->toBeNull();
});

it('keeps the package behaviour in the subclass', function () {
    $this->withHeaders(['Accept' => self::PAGE_ACCEPT])->post('/settings')
        ->assertStatus(303)
        ->assertRedirect('/settings');
});
