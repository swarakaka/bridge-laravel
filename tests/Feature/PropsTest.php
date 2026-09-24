<?php

declare(strict_types=1);

use Bridge\Bridge;
use Bridge\Negotiation\Mode;
use Bridge\Support\Headers;
use Bridge\Tests\Fixtures\Http\CustomerResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config()->set('bridge.shell.view', 'shell');

    Route::middleware('web')->get('/dashboard', fn () => Bridge::render('Dashboard', [
        'plain' => 'value',
        'closure' => fn () => 'from closure',
        'injected' => fn (Request $request) => $request->path(),
        'lazy' => Bridge::lazy(fn () => 'lazy value'),
        'stats' => Bridge::defer(fn () => ['users' => 10]),
        'revenue' => Bridge::defer(fn () => [1, 2, 3], 'charts'),
        'always' => Bridge::always(fn () => 'always here'),
        'nested' => ['a' => ['b' => 1, 'c' => 2], 'd' => 3],
        'enum' => Mode::Page,
        'date' => new DateTimeImmutable('2026-09-22T10:00:00+00:00'),
        'collection' => collect([1, 2]),
    ]));
});

it('resolves closures, hints and native values on full page loads', function () {
    $this->page('/dashboard')->assertBridgePage('Dashboard', fn ($page) => $page
        ->where('plain', 'value')
        ->where('closure', 'from closure')
        ->where('injected', 'dashboard')
        ->missing('lazy')
        ->missing('stats')
        ->missing('revenue')
        ->where('always', 'always here')
        ->where('enum', 'page')
        ->where('date', '2026-09-22T10:00:00+00:00')
        ->where('collection', [1, 2])
        ->deferred('default', ['stats'])
        ->deferred('charts', ['revenue']));
});

it('includes lazy and deferred props when named in a partial reload', function () {
    $this->page('/dashboard', [Headers::ONLY => 'lazy, stats', Headers::COMPONENT => 'Dashboard'])
        ->assertBridgePage('Dashboard', fn ($page) => $page
            ->where('lazy', 'lazy value')
            ->where('stats', ['users' => 10])
            ->where('always', 'always here')
            ->missing('plain')
            ->missing('revenue'));

    expect($this->page('/dashboard', [Headers::ONLY => 'lazy'])->json())->not->toHaveKey('deferred');
});

it('ignores partial headers when the component does not match', function () {
    $this->page('/dashboard', [Headers::ONLY => 'lazy', Headers::COMPONENT => 'Other'])
        ->assertBridgePage('Dashboard', fn ($page) => $page
            ->where('plain', 'value')
            ->missing('lazy')
            ->deferred('default', ['stats']));
});

it('applies except and nested dot selection', function () {
    $this->page('/dashboard', [Headers::EXCEPT => 'plain, nested.a.b'])
        ->assertBridgePage('Dashboard', fn ($page) => $page
            ->missing('plain')
            ->where('closure', 'from closure')
            ->where('nested', ['a' => ['c' => 2], 'd' => 3]));

    $this->page('/dashboard', [Headers::ONLY => 'nested.a.c, nested.d'])
        ->assertBridgePage('Dashboard', fn ($page) => $page
            ->where('nested', ['a' => ['c' => 2], 'd' => 3])
            ->missing('plain'));

    // Only wins over except.
    $this->page('/dashboard', [Headers::ONLY => 'plain', Headers::EXCEPT => 'plain'])
        ->assertBridgePage('Dashboard', fn ($page) => $page->where('plain', 'value')->missing('closure'));
});

it('resolves deferred props inline in JSON mode and excludes lazy ones', function () {
    $this->json_mode('GET', '/dashboard')
        ->assertJsonMode()
        ->assertJsonPath('data.stats.users', 10)
        ->assertJsonPath('data.revenue', [1, 2, 3])
        ->assertJsonMissingPath('data.lazy');

    $this->json_mode('GET', '/dashboard', [], [Headers::ONLY => 'lazy'])
        ->assertJsonPath('data.lazy', 'lazy value')
        ->assertJsonMissingPath('data.plain');
});

it('merges shared props with page props winning', function () {
    Bridge::share('auth', fn () => ['user' => ['id' => 1]]);
    Bridge::share(['plain' => 'shared', 'extra' => 'shared extra']);
    expect(Bridge::shared('extra'))->toBe('shared extra');

    $this->page('/dashboard')->assertBridgePage('Dashboard', fn ($page) => $page
        ->where('auth.user.id', 1)
        ->where('plain', 'value')
        ->where('extra', 'shared extra'));
});

it('drops props shared during a request once it is handled', function () {
    Route::middleware('web')->get('/signed-in', function () {
        Bridge::share('auth', ['user' => ['id' => 7]]);

        return Bridge::render('Dashboard');
    });
    Route::middleware('web')->get('/guest', fn () => Bridge::render('Dashboard'));

    $this->page('/signed-in')->assertBridgePage('Dashboard', fn ($page) => $page->where('auth.user.id', 7));
    $this->page('/guest')->assertBridgePage('Dashboard', fn ($page) => $page
        ->missing('auth')
        ->where('errors', [])
        ->where('flash', null));
});

it('exposes default errors and flash shared props', function () {
    $this->page('/dashboard')->assertBridgePage('Dashboard', fn ($page) => $page
        ->where('errors', [])
        ->where('flash', null));

    $this->withSession(['message' => 'Saved', 'level' => 'success'])
        ->page('/dashboard')
        ->assertBridgePage('Dashboard', fn ($page) => $page->where('flash', ['message' => 'Saved', 'level' => 'success']));
});

it('renders empty props as an object', function () {
    Bridge::flushShared();
    Route::middleware('web')->get('/empty', fn () => Bridge::render('Empty'));

    expect($this->page('/empty')->getContent())->toContain('"props":{}');

    Bridge::flushShared();
    expect($this->json_mode('GET', '/empty')->getContent())->toBe('{"data":{}}');
});

it('serializes resource collections with extra data as Laravel does, and plain ones as arrays', function () {
    $rows = collect([['id' => 1, 'name' => 'Acme', 'email' => 'a@acme.test']]);
    Route::middleware('web')->get('/collections', fn () => Bridge::render('Collections', [
        'plain' => CustomerResource::collection($rows),
        'extra' => CustomerResource::collection($rows)->additional(['meta' => ['total' => 1]]),
    ]));

    $this->json_mode('GET', '/collections')
        ->assertJsonPath('data.plain', [['id' => 1, 'name' => 'Acme', 'email' => 'a@acme.test']])
        ->assertJsonPath('data.extra', ['data' => [['id' => 1, 'name' => 'Acme', 'email' => 'a@acme.test']], 'meta' => ['total' => 1]]);
});
