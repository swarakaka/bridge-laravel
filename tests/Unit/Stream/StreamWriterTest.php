<?php

declare(strict_types=1);

use Bridge\Props\Serializer;
use Bridge\Stream\StreamMessage;
use Bridge\Stream\StreamWriter;
use Illuminate\Container\Container;
use Illuminate\Http\Request;

/**
 * @return list<array{event: ?string, data: mixed, id: ?string, raw: string}>
 */
function writerFrames(string $out): array
{
    $frames = [];

    foreach (array_filter(explode("\n\n", $out)) as $block) {
        $frame = ['event' => null, 'data' => null, 'id' => null, 'raw' => $block];

        foreach (explode("\n", $block) as $line) {
            [$field, $value] = array_map('trim', explode(':', $line, 2) + [1 => '']);

            if ($field === 'data') {
                $frame['data'] = json_decode($value, true);
            } elseif (in_array($field, ['event', 'id'], true)) {
                $frame[$field] = $value;
            }
        }

        $frames[] = $frame;
    }

    return $frames;
}

it('writes every producer convenience as a control frame', function () {
    $out = '';
    $writer = new StreamWriter(new Serializer(new Container), Request::create('/'), function (string $chunk) use (&$out): void {
        $out .= $chunk;
    }, false);

    $writer->retry(3000);
    $writer->heartbeat();
    $writer->notify('Saved', 'success', 'Done');
    $writer->navigate('/customers', true);
    $writer->progress('import', 0.5, 'Half');
    $writer->emit('customer.created', fn () => ['id' => 1]);
    $writer->emit('ping', 'pong');
    $writer->prop('count', new ArrayIterator([1, 2]));
    $writer->invalidate('customers');
    $writer->error(403, 'forbidden', 'No');
    $writer->message(StreamMessage::control(['type' => 'custom']), "7\n");
    $writer->end('done');
    $writer->end('again');

    $frames = writerFrames($out);

    expect($frames[0]['raw'])->toBe('retry: 3000')
        ->and($frames[1]['raw'])->toBe(': hb')
        ->and(array_column(array_slice($frames, 2), 'event'))->toBe(['bridge', 'bridge', 'bridge', 'customer.created', 'ping', 'bridge', 'bridge', 'bridge', 'bridge', 'bridge'])
        ->and($frames[2]['data'])->toBe(['type' => 'notification', 'level' => 'success', 'title' => 'Done', 'message' => 'Saved'])
        ->and($frames[3]['data'])->toBe(['type' => 'navigate', 'url' => '/customers', 'replace' => true])
        ->and($frames[4]['data'])->toBe(['type' => 'progress', 'id' => 'import', 'value' => 0.5, 'label' => 'Half'])
        ->and($frames[5]['data'])->toBe(['id' => 1])
        ->and($frames[6]['data'])->toBe(['value' => 'pong'])
        ->and($frames[7]['data'])->toBe(['type' => 'prop', 'key' => 'count', 'value' => [1, 2], 'mode' => 'replace'])
        ->and($frames[8]['data'])->toBe(['type' => 'invalidate', 'keys' => ['customers']])
        ->and($frames[9]['data'])->toBe(['type' => 'error', 'status' => 403, 'kind' => 'forbidden', 'message' => 'No', 'final' => true])
        ->and($frames[10]['id'])->toBe('7')
        ->and($frames[11]['data'])->toBe(['type' => 'end', 'reason' => 'done', 'reconnect' => false])
        ->and($frames)->toHaveCount(12)
        ->and($writer->ended())->toBeTrue()
        ->and($writer->aborted())->toBeFalse()
        ->and($writer->idleMs())->toBeGreaterThanOrEqual(0);
});

it('echoes and flushes when no output callback is given', function () {
    $writer = new StreamWriter(new Serializer(new Container), Request::create('/'), null, false);

    // raw() calls ob_flush(), which hands the buffer to this callback instead of stdout.
    $out = '';
    ob_start(function (string $buffer) use (&$out): string {
        $out .= $buffer;

        return '';
    });

    try {
        $writer->comment('tick');
        $writer->event('custom', "line1\nline2", 'id');
    } finally {
        ob_end_clean();
    }

    expect($out)->toBe(": tick\n\nid: id\nevent: custom\ndata: line1 line2\n\n");
});
