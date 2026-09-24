<?php

declare(strict_types=1);

use Bridge\Bridge;
use Bridge\Support\Headers;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config()->set('bridge.shell.view', 'shell');
    Bridge::flushShared();

    Route::middleware('web')->get('/board', fn () => Bridge::render('Board', [
        'plans' => Bridge::defer(fn () => ['basic'], 'slow')->once(ttl: 60),
        'feed' => Bridge::defer(fn () => ['data' => [['id' => 1]]])->merge()->matchOn('data.id'),
        'activity' => Bridge::lazy(fn () => ['signed in'])->once(key: 'activity'),
        'comments' => Bridge::lazy(fn () => [['id' => 9]])->prepend()->matchOn('id'),
        'title' => 'Board',
    ]));
});

$partial = fn (string $only, array $extra = []): array => [Headers::ONLY => $only, Headers::COMPONENT => 'Board'] + $extra;

it('defers a deferred-once prop until the client holds it, then neither sends nor defers it', function () use ($partial) {
    $this->page('/board')
        ->assertJsonPath('deferred', ['slow' => ['plans'], 'default' => ['feed']])
        ->assertJsonMissingPath('props.plans')
        ->assertJsonMissingPath('meta.once');

    // The deferred request resolves it and lists it for the client to keep.
    $this->page('/board', $partial('plans'))
        ->assertJsonPath('props.plans', ['basic'])
        ->assertJsonPath('meta.once.plans.key', 'plans');

    $this->page('/board', [Headers::ONCE => 'plans'])
        ->assertJsonPath('deferred', ['default' => ['feed']])
        ->assertJsonMissingPath('props.plans')
        ->assertJsonPath('meta.once.plans.key', 'plans');
});

it('defers a held deferred-once prop again when it is marked fresh', function () {
    Route::middleware('web')->get('/fresh', fn () => Bridge::render('Board', [
        'plans' => Bridge::defer(fn () => ['new'])->once()->fresh(),
    ]));

    $this->page('/fresh', [Headers::ONCE => 'plans'])
        ->assertJsonPath('deferred', ['default' => ['plans']])
        ->assertJsonMissingPath('meta.once');
});

it('lists a deferred-merge prop for merging when its deferred request sends it', function () use ($partial) {
    $this->page('/board')->assertJsonMissingPath('meta.merge');

    $this->page('/board', $partial('feed'))
        ->assertJsonPath('props.feed.data.0.id', 1)
        ->assertJsonPath('meta.merge', ['feed'])
        ->assertJsonPath('meta.matchOn', ['feed' => ['data.id']]);
});

it('fills a held lazy-once prop and resolves it when named', function () use ($partial) {
    $this->page('/board')->assertJsonMissingPath('props.activity')->assertJsonMissingPath('meta.once');

    $this->page('/board', [Headers::ONCE => 'activity'])
        ->assertJsonMissingPath('props.activity')
        ->assertJsonPath('meta.once.activity', ['key' => 'activity', 'expiresAt' => null]);

    $this->page('/board', $partial('activity', [Headers::ONCE => 'activity']))
        ->assertJsonPath('props.activity', ['signed in'])
        ->assertJsonPath('meta.once.activity.key', 'activity');
});

it('lists a lazy-merge prop in its mode when named', function () use ($partial) {
    $this->page('/board', $partial('comments'))
        ->assertJsonPath('meta.prepend', ['comments'])
        ->assertJsonPath('meta.matchOn', ['comments' => ['id']]);
});

it('resolves every combination inline in JSON mode without merge or once members', function () {
    $this->json_mode('GET', '/board', [], [Headers::ONCE => 'plans'])
        ->assertJsonPath('data.plans', ['basic'])
        ->assertJsonPath('data.feed.data.0.id', 1)
        ->assertJsonMissingPath('data.activity')
        ->assertJsonMissingPath('meta');
});

it('refuses combinations without a meaning', function () {
    expect(fn () => Bridge::always(1)->merge())->toThrow(LogicException::class, 'always')
        ->and(fn () => Bridge::always(1)->once())->toThrow(LogicException::class, 'always')
        ->and(fn () => Bridge::defer(fn () => 1)->once()->merge())->toThrow(LogicException::class, 'once and merge')
        ->and(fn () => Bridge::lazy(fn () => 1)->prepend()->once())->toThrow(LogicException::class, 'merge and once')
        ->and(fn () => Bridge::merge([])->once())->toThrow(LogicException::class)
        ->and(fn () => Bridge::once([])->matchOn('id'))->toThrow(LogicException::class)
        ->and(fn () => Bridge::defer(fn () => 1)->fresh())->toThrow(LogicException::class, 'once() first');
});

it('returns copies and keeps the deferred group', function () {
    $deferred = Bridge::defer(fn () => 1, 'charts');
    $once = $deferred->once(key: 'k');
    $merged = $deferred->matchOn('id');

    expect($deferred->onceOptions())->toBeNull()
        ->and($deferred->mergeOptions())->toBeNull()
        ->and($once->group)->toBe('charts')
        ->and($once->onceOptions()?->key)->toBe('k')
        ->and($merged->mergeOptions()?->mode)->toBe('append')
        ->and($merged->mergeOptions()?->matchOn)->toBe(['id']);
});
