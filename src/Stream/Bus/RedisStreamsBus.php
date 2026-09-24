<?php

declare(strict_types=1);

namespace Bridge\Stream\Bus;

use Bridge\Stream\Contracts\EventBus;
use Bridge\Stream\Contracts\ReplayWindow;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;

/**
 * Redis Streams: one stream per channel, XADD with MAXLEN, XREAD BLOCK across
 * all subscribed streams. Ids are time-based, so one cursor spans channels.
 * Raw commands keep the driver client-agnostic (phpredis and predis).
 */
final class RedisStreamsBus implements EventBus, ReplayWindow
{
    public function __construct(
        private readonly RedisFactory $redis,
        private readonly ?string $connection = null,
        private readonly int $maxLen = 1000,
        private readonly string $prefix = 'bridge',
        /** Seconds a channel's key lives after its last publish; null keeps keys forever. */
        private readonly ?int $retainSeconds = null,
        /**
         * Longest XREAD BLOCK, in ms. Blocking past the client's read timeout makes
         * phpredis/predis throw, so this stays below it; the stream loop simply
         * reads again and still sends heartbeats on time.
         */
        private readonly ?int $maxBlockMs = null,
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

            // Sliding expiry: channels nobody publishes to (per-user, per-tenant) do not pile up.
            if ($this->retainSeconds !== null) {
                $this->raw(['EXPIRE', $this->key($channel), (string) $this->retainSeconds]);
            }
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
            ['XREAD', 'BLOCK', (string) max(1, $this->maxBlockMs === null ? $blockMs : min($blockMs, $this->maxBlockMs)), 'STREAMS'],
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
        return new Cursor($this->nowMs().'-0');
    }

    /**
     * MAXLEN trimming drops the oldest entries. Redis 7+ counts every entry ever
     * added (`entries-added`) and records the highest id removed by XDEL
     * (`max-deleted-entry-id`); trimming shows only as a shorter `length`. When
     * something was dropped and the oldest kept entry is newer than the client's
     * id, events after it may be gone. Older servers lack the counters and are
     * judged by the oldest entry alone, which may resync a client that missed nothing.
     */
    public function canReplayFrom(array $channels, string $lastEventId): bool
    {
        if (preg_match('/^(\d+)-\d+$/', $lastEventId, $match) !== 1 || (int) $match[1] > $this->nowMs()) {
            return false;
        }

        foreach ($channels as $channel) {
            $key = $this->key($channel);

            if ((int) $this->raw(['EXISTS', $key]) === 0) {
                // Every publish pushes the expiry to at least retain seconds later, so a
                // missing key means nothing was published after an id younger than that.
                // An older id may have had events that expired with the key.
                if ($this->retainSeconds !== null && (int) $match[1] < $this->nowMs() - $this->retainSeconds * 1000) {
                    return false;
                }

                continue;
            }

            $info = $this->pairs($this->raw(['XINFO', 'STREAM', $key]));
            $deleted = $info['max-deleted-entry-id'] ?? null;

            if (is_string($deleted) && Cursor::compare($deleted, $lastEventId) > 0) {
                return false;
            }

            $dropped = ! isset($info['entries-added'], $info['length']) || (int) $info['entries-added'] > (int) $info['length'];
            $first = $info['first-entry'] ?? null;

            if ($dropped && is_array($first) && isset($first[0]) && Cursor::compare((string) $first[0], $lastEventId) > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Delete the channels' streams (used by bridge:doctor after its roundtrip).
     *
     * @param  list<string>  $channels
     */
    public function forget(array $channels): void
    {
        foreach ($channels as $channel) {
            $this->raw(['DEL', $this->key($channel)]);
        }
    }

    private function nowMs(): int
    {
        $time = $this->raw(['TIME']);

        return is_array($time) && isset($time[0], $time[1])
            ? (int) $time[0] * 1000 + intdiv((int) $time[1], 1000)
            : (int) (microtime(true) * 1000);
    }

    /**
     * A flat [name, value, ...] reply (RESP2) or an already keyed map (RESP3).
     *
     * @return array<string, mixed>
     */
    private function pairs(mixed $reply): array
    {
        if (! is_array($reply)) {
            return [];
        }

        if (! array_is_list($reply)) {
            return $reply;
        }

        $out = [];

        for ($i = 0; $i + 1 < count($reply); $i += 2) {
            $out[(string) $reply[$i]] = $reply[$i + 1];
        }

        return $out;
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
