<?php

declare(strict_types=1);

use Bridge\Errors\ErrorKind;
use Bridge\Negotiation\Mode;
use Bridge\Ssr\NullSsrGateway;
use Bridge\Stream\Bus\Cursor;
use Bridge\Stream\Bus\Envelope;
use Bridge\Stream\Bus\NullBus;

it('discards everything on the null bus and waits out the block time', function () {
    $bus = new NullBus;
    $started = microtime(true);

    expect($bus->publish(['a'], Envelope::make('x', [])))->toBe('0')
        ->and(iterator_to_array($bus->read(['a'], Cursor::start(), 20)))->toBe([])
        ->and(microtime(true) - $started)->toBeGreaterThanOrEqual(0.015)
        ->and(iterator_to_array($bus->read(['a'], Cursor::start(), 0)))->toBe([])
        ->and($bus->latestCursor(['a'])->fallback)->toBe('0')
        ->and($bus->supportsReplay())->toBeFalse();
});

it('renders nothing through the null ssr gateway', function () {
    expect((new NullSsrGateway)->render(['component' => 'X']))->toBeNull();
});

it('maps http statuses to error kinds', function (int $status, ErrorKind $kind) {
    expect(ErrorKind::fromStatus($status))->toBe($kind);
})->with([
    [401, ErrorKind::Unauthenticated],
    [403, ErrorKind::Forbidden],
    [404, ErrorKind::NotFound],
    [409, ErrorKind::Conflict],
    [419, ErrorKind::Csrf],
    [422, ErrorKind::Validation],
    [429, ErrorKind::Throttled],
    [418, ErrorKind::Http],
    [503, ErrorKind::Server],
]);

it('ranks modes for tie-breaks and lists their media types', function () {
    expect(array_map(fn (Mode $m) => $m->rank(), [Mode::Stream, Mode::Page, Mode::Json, Mode::Html]))->toBe([1, 2, 3, 4])
        ->and(Mode::Html->mediaTypes())->toBe(['text/html', 'application/xhtml+xml'])
        ->and(Mode::Stream->isRendering())->toBeFalse()
        ->and(Mode::Page->isRendering())->toBeTrue();
});
