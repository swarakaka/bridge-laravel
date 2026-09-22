<?php

declare(strict_types=1);

namespace Bridge\Stream\Bus;

use Bridge\Stream\Contracts\EventBus;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;

/**
 * Redis Streams: one stream per channel, XADD with MAXLEN, XREAD BLOCK across
 * all subscribed streams. Ids are time-based, so one cursor spans channels.
 * Raw commands keep the driver client-agnostic (phpredis and predis).
 */
final class RedisStreamsBus implements EventBus
{
    public function __construct(
        private readonly RedisFactory $redis,
        private readonly ?string $connection = null,
        private readonly int $maxLen = 1000,
        private readonly string $prefix = 'bridge',
    ) {}

    public function publish(array $channels, Envelope $envelope): string
    {
        $id = '0-0';
        $fields = [
            'uuid' => $envelope->uuid,
            'event' => $envelope->event,
            'data' => json_encode($envelope->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'published_at' => (string) ($envelope->publishedAt ?? microtime(true)),
        ];

        foreach ($channels as $channel) {
            $args = ['XADD', $this->key($channel), 'MAXLEN', '~', (string) $this->maxLen, '*'];

            foreach ($fields as $name => $value) {
                $args[] = $name;
                $args[] = $value;
            }

            $id = (string) $this->raw($args);
        }

        return $id;
    }

    public function read(array $channels, Cursor $since, int $blockMs): iterable
    {
        $keys = [];
        $ids = [];
        $keyToChannel = [];

        foreach ($channels as $channel) {
            $key = $this->key($channel);
            $keys[] = $key;
            $ids[] = $since->for($channel) ?? '0-0';
            $keyToChannel[$key] = $channel;
        }

        $reply = $this->raw(array_merge(
            ['XREAD', 'BLOCK', (string) max(1, $blockMs), 'STREAMS'],
            $keys,
            $ids,
        ));

        if (! is_array($reply) || $reply === []) {
            return [];
        }

        $out = [];

        foreach ($reply as $stream) {
            if (! is_array($stream) || count($stream) < 2 || ! is_array($stream[1])) {
                continue;
            }

            $channel = $keyToChannel[(string) $stream[0]] ?? (string) $stream[0];

            foreach ($stream[1] as $entry) {
                if (! is_array($entry) || count($entry) < 2 || ! is_array($entry[1])) {
                    continue;
                }

                $fields = [];
                $pairs = array_values($entry[1]);

                for ($i = 0; $i + 1 < count($pairs); $i += 2) {
                    $fields[(string) $pairs[$i]] = $pairs[$i + 1];
                }

                $out[] = Envelope::fromArray([
                    'uuid' => $fields['uuid'] ?? null,
                    'event' => $fields['event'] ?? 'bridge',
                    'data' => json_decode((string) ($fields['data'] ?? '{}'), true) ?: [],
                    'published_at' => $fields['published_at'] ?? null,
                ], (string) $entry[0], $channel);
            }
        }

        usort($out, fn (Envelope $a, Envelope $b) => Cursor::compare((string) $a->id, (string) $b->id));

        return $out;
    }

    public function latestCursor(array $channels): Cursor
    {
        $time = $this->raw(['TIME']);
        $ms = is_array($time) && isset($time[0], $time[1])
            ? (int) $time[0] * 1000 + intdiv((int) $time[1], 1000)
            : (int) (microtime(true) * 1000);

        return new Cursor($ms.'-0');
    }

    public function supportsReplay(): bool
    {
        return true;
    }

    private function conn(): Connection
    {
        return $this->redis->connection($this->connection);
    }

    /**
     * phpredis exposes executeRaw() on the connection; predis reaches its
     * client's executeRaw() through __call.
     *
     * @param  list<string>  $args
     */
    private function raw(array $args): mixed
    {
        $conn = $this->conn();

        if (method_exists($conn, 'executeRaw')) {
            return $conn->executeRaw($args);
        }

        return $conn->command('executeRaw', [$args]);
    }

    private function key(string $channel): string
    {
        return $this->prefix.':stream:'.$channel;
    }
}
