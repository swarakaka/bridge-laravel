<?php

declare(strict_types=1);

use Bridge\Bridge;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config()->set('bridge.shell.view', 'shell');
});

it('accumulates props, meta, status and last-modified through the fluent api', function () {
    $when = new DateTimeImmutable('2026-01-02T03:04:05Z');

    Route::middleware('web')->get('/fluent', fn () => Bridge::render('Fluent', ['a' => 1])
        ->with(['b' => 2])
        ->withMeta(['title' => 'T'])
        ->status(201)
        ->lastModified($when)
        ->shell('shell'));

    Route::middleware('web')->get('/cached', fn () => Bridge::render('Fluent', [])
        ->lastModified($when)
        ->cache(maxAge: 60));

    $this->page('/fluent')
        ->assertStatus(201)
        ->assertHeader('Last-Modified', 'Fri, 02 Jan 2026 03:04:05 GMT')
        ->assertJsonPath('props.a', 1)
        ->assertJsonPath('props.b', 2)
        ->assertJsonPath('meta.title', 'T');

    $this->page('/cached')
        ->assertHeader('Last-Modified', 'Fri, 02 Jan 2026 03:04:05 GMT')
        ->assertHeader('Cache-Control', 'max-age=60, must-revalidate, private');

    $this->page('/cached', ['If-Modified-Since' => 'Fri, 02 Jan 2026 03:04:05 GMT'])->assertStatus(304);

    $this->html('/fluent')->assertStatus(201)->assertSee('id="bridge-page"', false);
});

it('exposes the unresolved page', function () {
    $response = Bridge::render('X', ['a' => 1])->with(['b' => 2])->withMeta(['m' => true]);

    expect($response->page()->component)->toBe('X')
        ->and($response->page()->props)->toBe(['a' => 1, 'b' => 2])
        ->and($response->page()->meta)->toBe(['m' => true]);
});
