<?php

declare(strict_types=1);

use Bridge\Bridge;
use Bridge\Support\Headers;
use Bridge\Tests\Fixtures\Http\CustomerResource;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config()->set('bridge.shell.view', 'shell');
    Bridge::flushShared();
});

function rows(int $from, int $count, bool $ids = true): array
{
    return array_map(
        fn (int $i) => ($ids ? ['id' => $i] : []) + ['name' => "C{$i}", 'email' => "c{$i}@example.test"],
        range($from, $from + $count - 1),
    );
}

function scrollRoute(string $uri, Closure $value): void
{
    Route::middleware('web')->get($uri, fn () => Bridge::render('List', ['items' => Bridge::scroll($value())]));
}

it('describes a length-aware page, merges it and matches on data.id', function () {
    scrollRoute('/list', fn () => new LengthAwarePaginator(rows(21, 20), 57, 20, 2, ['path' => '/list']));

    $this->page('/list?page=2')
        ->assertJsonPath('meta.scroll.items', [
            'pageName' => 'page', 'dataPath' => 'data', 'currentPage' => 2, 'previousPage' => 1, 'nextPage' => 3,
        ])
        ->assertJsonPath('meta.merge', ['items'])
        ->assertJsonPath('meta.matchOn', ['items' => ['data.id']])
        ->assertJsonPath('props.items.data.0.id', 21);
});

it('has no previous page on the first page and no next page on the last', function () {
    scrollRoute('/first', fn () => new LengthAwarePaginator(rows(1, 20), 57, 20, 1, ['path' => '/first']));
    scrollRoute('/last', fn () => new LengthAwarePaginator(rows(41, 17), 57, 20, 3, ['path' => '/last']));

    $this->page('/first')->assertJsonPath('meta.scroll.items.previousPage', null)->assertJsonPath('meta.scroll.items.nextPage', 2);
    $this->page('/last')->assertJsonPath('meta.scroll.items.previousPage', 2)->assertJsonPath('meta.scroll.items.nextPage', null);
});

it('uses hasMorePages for simple paginators and a custom page name', function () {
    Route::middleware('web')->get('/simple', fn () => Bridge::render('List', [
        'items' => Bridge::scroll(new Paginator(rows(1, 11), 10, 1, ['pageName' => 'p']), 'p'),
    ]));
    Route::middleware('web')->get('/simple-end', fn () => Bridge::render('List', [
        'items' => Bridge::scroll(new Paginator(rows(11, 3), 10, 2)),
    ]));

    $this->page('/simple')->assertJsonPath('meta.scroll.items.pageName', 'p')->assertJsonPath('meta.scroll.items.nextPage', 2);
    $this->page('/simple-end')->assertJsonPath('meta.scroll.items.nextPage', null)->assertJsonPath('meta.scroll.items.previousPage', 1);
});

it('gives cursor strings for cursor paginators', function () {
    scrollRoute('/cursor', fn () => new CursorPaginator(rows(1, 11), 10, new Cursor(['id' => 0]), ['parameters' => ['id'], 'path' => '/cursor']));

    $scroll = $this->page('/cursor')->json('meta.scroll.items');

    expect($scroll['pageName'])->toBe('cursor')
        ->and($scroll['currentPage'])->toBeString()
        ->and($scroll['nextPage'])->toBeString()
        ->and(Cursor::fromEncoded($scroll['nextPage'])?->parameter('id'))->toBe(10);
});

it('reads the paginator behind a resource collection', function () {
    scrollRoute('/resources', fn () => CustomerResource::collection(new LengthAwarePaginator(rows(1, 20), 40, 20, 1, ['path' => '/resources'])));

    $this->page('/resources')
        ->assertJsonPath('meta.scroll.items.nextPage', 2)
        ->assertJsonPath('meta.matchOn.items', ['data.id']);
});

it('matches only when every item has an id, and follows an explicit matchOn', function () {
    scrollRoute('/no-ids', fn () => new LengthAwarePaginator(rows(1, 3, ids: false), 3, 20, 1, ['path' => '/no-ids']));
    Route::middleware('web')->get('/explicit', fn () => Bridge::render('List', [
        'items' => Bridge::scroll(new LengthAwarePaginator(rows(1, 3), 3, 20, 1))->matchOn('data.name'),
        'plain' => Bridge::scroll(new LengthAwarePaginator(rows(1, 3), 3, 20, 1))->matchOn(),
    ]));

    $this->page('/no-ids')->assertJsonMissingPath('meta.matchOn');
    $this->page('/explicit')->assertJsonPath('meta.matchOn', ['items' => ['data.name']]);
});

it('lists the scroll entry only when the prop is sent, and never in JSON mode', function () {
    Route::middleware('web')->get('/two', fn () => Bridge::render('List', [
        'items' => Bridge::scroll(fn () => new LengthAwarePaginator(rows(1, 20), 40, 20, 1)),
        'title' => 'x',
    ]));

    $this->page('/two', [Headers::ONLY => 'title', Headers::COMPONENT => 'List'])->assertJsonMissingPath('meta');
    $this->json_mode('GET', '/two')->assertJsonPath('data.items.data.0.id', 1)->assertJsonMissingPath('meta');
});

it('refuses values that are not paginated', function () {
    Route::middleware('web')->get('/bad', fn () => Bridge::render('List', ['items' => Bridge::scroll([1, 2])]));

    $this->withoutExceptionHandling();
    expect(fn () => $this->page('/bad'))->toThrow(InvalidArgumentException::class, 'Prop [items]');
    expect(fn () => Bridge::scroll([])->once())->toThrow(LogicException::class);
});
