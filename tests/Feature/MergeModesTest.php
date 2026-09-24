<?php

declare(strict_types=1);

use Bridge\Bridge;
use Bridge\Props\Merge;
use Bridge\Support\Headers;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config()->set('bridge.shell.view', 'shell');
    Bridge::flushShared();

    Route::middleware('web')->get('/feed', fn () => Bridge::render('Feed', [
        'customers' => Bridge::merge(fn () => ['data' => [['id' => 1]]])->matchOn('data.id'),
        'messages' => Bridge::merge([['id' => 7]])->prepend()->matchOn('id'),
        'settings' => Bridge::deepMerge(['theme' => ['dark' => true]]),
        'plain' => 1,
    ]));
});

it('lists each merge key under its mode, with match paths', function () {
    $this->page('/feed')
        ->assertJsonPath('meta.merge', ['customers'])
        ->assertJsonPath('meta.prepend', ['messages'])
        ->assertJsonPath('meta.deepMerge', ['settings'])
        ->assertJsonPath('meta.matchOn', ['customers' => ['data.id'], 'messages' => ['id']]);
});

it('lists only the selected keys in a partial response', function () {
    $this->page('/feed', [Headers::ONLY => 'messages', Headers::COMPONENT => 'Feed'])
        ->assertJsonPath('meta', ['prepend' => ['messages'], 'matchOn' => ['messages' => ['id']]]);
});

it('leaves the merge members out of JSON mode', function () {
    $this->json_mode('GET', '/feed')
        ->assertJsonPath('data.messages', [['id' => 7]])
        ->assertJsonMissingPath('meta');
});

it('returns copies from the modifiers', function () {
    $append = Bridge::merge([1]);
    $prepend = $append->prepend()->matchOn('id');

    expect($append->mode)->toBe(Merge::APPEND)
        ->and($append->matchOn)->toBe([])
        ->and($prepend->mode)->toBe(Merge::PREPEND)
        ->and($prepend->matchOn)->toBe(['id'])
        ->and($prepend->append()->mode)->toBe(Merge::APPEND)
        ->and($prepend->deep()->matchOn)->toBe(['id'])
        ->and($prepend->matchOn()->matchOn)->toBe([]);
});

it('refuses bad match paths and wrapped hints', function () {
    expect(fn () => Bridge::merge([])->matchOn('data id'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Bridge::merge([])->matchOn('a,b'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Bridge::merge(Bridge::lazy(fn () => 1)))->toThrow(InvalidArgumentException::class);
});
