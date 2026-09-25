<?php

declare(strict_types=1);

use Bridge\Bridge;
use Bridge\Props\Watch;
use Bridge\Stream\WatchTags;
use Bridge\Support\Headers;
use Bridge\Tests\Fixtures\Models\WatchedCustomer;
use Bridge\Tests\Fixtures\Models\WatchedNote;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Bridge::flushShared();
});

afterEach(function () {
    Relation::morphMap([], false);
});

function watchedCustomer(int $id = 12): WatchedCustomer
{
    return (new WatchedCustomer)->forceFill(['id' => $id, 'tenant_id' => 7, 'name' => 'Acme']);
}

it('lists watched props with their tags in meta.watch', function () {
    Route::middleware('web')->get('/w', fn () => Bridge::render('W', [
        'customers' => Bridge::watch(fn () => [1, 2], WatchedCustomer::class),
        'customer' => Bridge::watch(['id' => 12], watchedCustomer()),
        'report' => Bridge::watch(['x' => 1], 'reports', WatchedCustomer::class, 'reports'),
        'plain' => 1,
    ]));

    $page = $this->page('/w')->assertOk()->json();

    expect($page['props'])->toBe(['customers' => [1, 2], 'customer' => ['id' => 12], 'report' => ['x' => 1], 'plain' => 1])
        ->and($page['meta']['watch'])->toBe([
            'customers' => ['customers'],
            'customer' => ['customers.12'],
            'report' => ['reports', 'customers'],
        ]);
});

it('lists a prop whenever its value is sent or held, for every delivery', function () {
    Route::middleware('web')->get('/w', fn () => Bridge::render('W', [
        'stats' => Bridge::defer(fn () => 1)->watch('stats'),
        'activity' => Bridge::lazy(fn () => 2)->watch('activity'),
        'auth' => Bridge::always(['id' => 1])->watch('users.1'),
        'feed' => Bridge::merge([1])->watch('feed'),
        'plans' => Bridge::once(fn () => ['a'])->watch('plans'),
        'held' => Bridge::defer(fn () => 3)->once()->watch('held'),
    ]));

    $full = $this->page('/w', [Headers::ONCE => 'held'])->assertOk()->json();

    // Deferred and lazy values are absent; the held deferred-once value is filled by the client.
    expect(array_keys($full['meta']['watch']))->toBe(['auth', 'feed', 'plans', 'held'])
        ->and($full['meta']['merge'])->toBe(['feed']);

    $partial = $this->page('/w', [Headers::ONLY => 'stats,activity', Headers::COMPONENT => 'W'])->assertOk()->json();

    expect($partial['meta']['watch'])->toBe(['stats' => ['stats'], 'activity' => ['activity'], 'auth' => ['users.1']]);
});

it('watches scroll props', function () {
    $paginator = new LengthAwarePaginator([['id' => 1]], total: 3, perPage: 1, currentPage: 1, options: ['path' => '/w']);
    Route::middleware('web')->get('/w', fn () => Bridge::render('W', [
        'customers' => Bridge::scroll($paginator)->watch(WatchedCustomer::class),
    ]));

    $page = $this->page('/w')->assertOk()->json();

    expect($page['meta']['watch'])->toBe(['customers' => ['customers']])
        ->and($page['meta']['scroll'])->toHaveKey('customers');
});

it('leaves meta.watch out of JSON mode and embeds it in the HTML shell', function () {
    config()->set('bridge.shell.view', 'shell');
    Route::middleware('web')->get('/w', fn () => Bridge::render('W', [
        'customers' => Bridge::watch([1], WatchedCustomer::class),
    ]));

    $json = $this->json_mode('GET', '/w')->assertOk()->json();

    expect($json)->toBe(['data' => ['customers' => [1]]]);

    $this->html('/w')->assertBridgePage('W', function ($page) {
        expect($page->toArray()['meta']['watch'])->toBe(['customers' => ['customers']]);
    });
});

it('names tags after the morph class in the class style', function () {
    config()->set('bridge.watch.tags', 'class');
    app()->forgetInstance(WatchTags::class);
    Route::middleware('web')->get('/w', fn () => Bridge::render('W', [
        'customer' => Bridge::watch([1], watchedCustomer()),
        'notes' => Bridge::watch([1], WatchedNote::class),
    ]));

    expect($this->page('/w')->json('meta.watch'))->toBe([
        'customer' => [WatchedCustomer::class.'.12'],
        'notes' => [WatchedNote::class],
    ]);

    Relation::morphMap(['customer' => WatchedCustomer::class]);

    expect($this->page('/w')->json('meta.watch.customer'))->toBe(['customer.12']);
});

it('lets a model choose its tag', function () {
    $model = new class extends WatchedCustomer
    {
        public function bridgeTag(): string
        {
            return 'clients';
        }
    };

    $tags = app(WatchTags::class);

    expect($tags->forWatch($model->forceFill(['id' => 3])))->toBe(['clients.3'])
        ->and($tags->forWatch($model::class))->toBe(['clients'])
        ->and($tags->forChange($model))->toBe(['clients', 'clients.3']);
});

it('refuses invalid tags and sourceless watches', function (Closure $build, string $message) {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'wildcard' => [fn () => Bridge::watch(1, 'customers.*'), 'Invalid watch tag [customers.*]'],
    'comma' => [fn () => Bridge::lazy(fn () => 1)->watch('a,b'), 'Invalid watch tag [a,b]'],
    'space' => [fn () => Bridge::watch(1, 'a b'), 'Invalid watch tag [a b]'],
    'none' => [fn () => new Watch(1), 'needs at least one source'],
    'nested' => [fn () => Bridge::watch(Bridge::lazy(fn () => 1), 'a'), 'cannot wrap another prop hint'],
    'style' => [fn () => new WatchTags('uuid'), "must be 'table' or 'class'"],
]);

it('returns copies from watch()', function () {
    $hint = Bridge::defer(fn () => 1);
    $watched = $hint->watch('a')->watch('b', 'a');

    expect($hint->watchSources())->toBe([])
        ->and($watched->watchSources())->toBe(['a', 'b']);
});
