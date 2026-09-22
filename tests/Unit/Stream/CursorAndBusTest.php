<?php

declare(strict_types=1);

use Bridge\Stream\Bus\Cursor;
use Bridge\Stream\Bus\Envelope;
use Bridge\Stream\Bus\SyncBus;
use Bridge\Stream\ChannelAuthorizer;
use Bridge\Stream\StreamMessage;
use Illuminate\Container\Container;

it('compares numeric, redis-style and string ids', function () {
    expect(Cursor::compare('10', '9'))->toBe(1)
        ->and(Cursor::compare('1700-1', '1700-0'))->toBe(1)
        ->and(Cursor::compare('1699-9', '1700-0'))->toBe(-1)
        ->and(Cursor::compare('b', 'a'))->toBe(1);
});

it('advances per channel and keeps the highest fallback', function () {
    $cursor = Cursor::fromLastEventId('5')->advance('a', '7')->advance('b', '6');

    expect($cursor->for('a'))->toBe('7')
        ->and($cursor->for('b'))->toBe('6')
        ->and($cursor->for('c'))->toBe('7');
});

it('publishes and reads from the sync bus with replay', function () {
    $bus = new SyncBus;
    $start = $bus->latestCursor(['a']);
    $bus->publish(['a', 'b'], Envelope::make('x', ['n' => 1]));
    $bus->publish(['b'], Envelope::make('y', ['n' => 2]));

    $a = iterator_to_array($bus->read(['a'], $start, 0));
    $all = iterator_to_array($bus->read(['a', 'b'], Cursor::start(), 0));

    expect($a)->toHaveCount(1)
        ->and($a[0]->event)->toBe('x')
        ->and($a[0]->id)->toBe('1')
        ->and($all)->toHaveCount(3)
        ->and(iterator_to_array($bus->read(['b'], Cursor::fromLastEventId('1'), 0)))->toHaveCount(1)
        ->and($bus->supportsReplay())->toBeTrue();
});

it('builds control messages per the protocol', function () {
    expect(StreamMessage::invalidate('customers')->data)->toBe(['type' => 'invalidate', 'keys' => ['customers']])
        ->and(StreamMessage::invalidate('*')->data['keys'])->toBe('*')
        ->and(StreamMessage::prop('n', 3)->data)->toBe(['type' => 'prop', 'key' => 'n', 'value' => 3, 'mode' => 'replace'])
        ->and(StreamMessage::end('max_duration', true)->type())->toBe('end')
        ->and(StreamMessage::event('customer.created', ['id' => 1])->isControl())->toBeFalse();

    expect(fn () => StreamMessage::event('bridge'))->toThrow(InvalidArgumentException::class);
});

it('authorizes channels with broadcast-style patterns', function () {
    $authorizer = new ChannelAuthorizer(new Container);
    $authorizer->register('tenant.{tenantId}', fn ($user, string $tenantId) => $user['tenant'] === (int) $tenantId);
    $authorizer->register('public', fn () => true);

    expect($authorizer->authorize(['tenant' => 7], 'tenant.7'))->toBeTrue()
        ->and($authorizer->authorize(['tenant' => 7], 'tenant.8'))->toBeFalse()
        ->and($authorizer->authorize(null, 'public'))->toBeTrue()
        ->and($authorizer->authorize(null, 'unknown'))->toBeFalse()
        ->and($authorizer->authorize(['tenant' => 7], 'tenant.7.extra'))->toBeFalse();
});
