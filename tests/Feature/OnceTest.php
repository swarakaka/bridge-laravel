<?php

declare(strict_types=1);

use Bridge\Bridge;
use Bridge\Props\Once;
use Bridge\Support\Headers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config()->set('bridge.shell.view', 'shell');
    Bridge::flushShared();
    $this->calls = 0;

    Route::middleware('web')->get('/plans', function () {
        return Bridge::render('Plans', [
            'plans' => Bridge::once(function () {
                $this->calls++;

                return ['basic', 'pro'];
            }),
            'title' => 'Plans',
        ]);
    });
});

it('sends the value and lists it in meta.once without the header', function () {
    $this->page('/plans')
        ->assertOk()
        ->assertJsonPath('props.plans', ['basic', 'pro'])
        ->assertJsonPath('meta.once', ['plans' => ['key' => 'plans', 'expiresAt' => null]])
        ->assertHeader('Vary', Headers::PAGE_VARY);

    expect($this->calls)->toBe(1);
});

it('leaves a held value out without resolving it, and keeps the meta.once entry', function () {
    $this->page('/plans', [Headers::ONCE => 'plans'])
        ->assertJsonMissingPath('props.plans')
        ->assertJsonPath('props.title', 'Plans')
        ->assertJsonPath('meta.once.plans.key', 'plans');

    expect($this->calls)->toBe(0);
});

it('resolves a held value when X-Bridge-Only names it or it is marked fresh', function () {
    $this->page('/plans', [Headers::ONCE => 'plans', Headers::ONLY => 'plans', Headers::COMPONENT => 'Plans'])
        ->assertJsonPath('props.plans', ['basic', 'pro'])
        ->assertJsonPath('meta.once.plans.key', 'plans');

    Route::middleware('web')->get('/fresh', fn () => Bridge::render('Plans', [
        'plans' => Bridge::once(fn () => ['new'])->fresh(),
    ]));
    $this->page('/fresh', [Headers::ONCE => 'plans'])->assertJsonPath('props.plans', ['new']);
});

it('lists only selected once props in a partial response', function () {
    $this->page('/plans', [Headers::ONLY => 'title', Headers::COMPONENT => 'Plans'])
        ->assertJsonPath('props', ['title' => 'Plans'])
        ->assertJsonMissingPath('meta');
});

it('shares one key between props and between pages', function () {
    Route::middleware('web')->get('/edit', fn () => Bridge::render('Edit', [
        'statuses' => Bridge::once(fn () => ['a'], key: 'customer-statuses'),
        'kinds' => Bridge::once(fn () => ['b'], key: 'customer-statuses'),
    ]));

    $this->page('/edit', [Headers::ONCE => 'customer-statuses'])
        ->assertJsonPath('props', [])
        ->assertJsonPath('meta.once.statuses.key', 'customer-statuses')
        ->assertJsonPath('meta.once.kinds.key', 'customer-statuses');
});

it('turns a ttl into expiresAt in milliseconds', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-24T12:00:00Z'));

    Route::middleware('web')->get('/ttl', fn () => Bridge::render('Ttl', [
        'seconds' => Bridge::once(fn () => 1, ttl: 60),
        'interval' => Bridge::once(fn () => 2, ttl: new DateInterval('PT1H')),
    ]));

    $now = Carbon::now()->getTimestampMs();
    $this->page('/ttl')
        ->assertJsonPath('meta.once.seconds.expiresAt', $now + 60_000)
        ->assertJsonPath('meta.once.interval.expiresAt', $now + 3_600_000);

    Carbon::setTestNow();
});

it('works as a shared prop', function () {
    Bridge::share('locale', Bridge::once(fn () => ['hello' => 'Hello']));
    Route::middleware('web')->get('/shared', fn () => Bridge::render('Shared'));

    $this->page('/shared')->assertJsonPath('props.locale.hello', 'Hello');
    $this->page('/shared', [Headers::ONCE => 'locale'])->assertJsonMissingPath('props.locale');
});

it('resolves inline in JSON mode and ignores the header there', function () {
    $this->json_mode('GET', '/plans', [], [Headers::ONCE => 'plans'])
        ->assertJsonPath('data.plans', ['basic', 'pro'])
        ->assertJsonMissingPath('meta.once')
        ->assertHeader('Vary', Headers::VARY);
});

it('always embeds the value in the HTML shell', function () {
    $this->html('/plans', [Headers::ONCE => 'plans'])->assertBridgePage('Plans', function ($page) {
        expect($page->toArray()['props']['plans'])->toBe(['basic', 'pro'])
            ->and($page->toArray()['meta']['once'])->toBe(['plans' => ['key' => 'plans', 'expiresAt' => null]]);
    });
});

it('refuses keys with commas or whitespace and nested hints', function () {
    expect(fn () => Bridge::once(1, key: 'a,b'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Bridge::once(1, key: 'a b'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Bridge::once(Bridge::lazy(fn () => 1)))->toThrow(InvalidArgumentException::class);

    Route::middleware('web')->get('/nested', fn () => Bridge::render('Nested', [
        'thing' => Bridge::always(fn () => Bridge::once(1)),
    ]));
    $this->withoutExceptionHandling();
    expect(fn () => $this->page('/nested'))->toThrow(LogicException::class, 'Prop [thing]');
});

it('keeps fresh() from changing the original hint', function () {
    $hint = new Once(fn () => 1);
    expect($hint->fresh()->onceOptions()?->fresh)->toBeTrue()
        ->and($hint->onceOptions()?->fresh)->toBeFalse();
});
