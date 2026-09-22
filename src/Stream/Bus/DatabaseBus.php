<?php

declare(strict_types=1);

namespace Bridge\Stream\Bus;

use Bridge\Stream\Contracts\EventBus;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Polling bus on a database table. Works everywhere; latency = poll interval.
 */
final class DatabaseBus implements EventBus
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly string $table = 'bridge_stream_events',
        private readonly int $pollMs = 1000,
        private readonly string $prefix = 'bridge',
    ) {}

    public function publish(array $channels, Envelope $envelope): string
    {
        $now = now();
        $id = '0';

        foreach ($channels as $channel) {
            $id = (string) $this->query()->insertGetId([
                'uuid' => $envelope->uuid,
                'channel' => $this->key($channel),
                'event' => $envelope->event,
                'data' => json_encode($envelope->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ]);
        }

        return $id;
    }

    public function read(array $channels, Cursor $since, int $blockMs): iterable
    {
        $deadline = microtime(true) + $blockMs / 1000;
        $keys = array_map(fn (string $c) => $this->key($c), $channels);
        $keyToChannel = array_combine($keys, $channels);
        $after = (int) ($since->fallback ?? '0');

        do {
            $rows = $this->query()
                ->whereIn('channel', $keys)
                ->where('id', '>', $after)
                ->orderBy('id')
                ->limit(200)
                ->get();

            if ($rows->isNotEmpty()) {
                $out = [];

                foreach ($rows as $row) {
                    $row = (array) $row;
                    $out[] = Envelope::fromArray([
                        'uuid' => $row['uuid'],
                        'event' => $row['event'],
                        'data' => json_decode((string) $row['data'], true) ?: [],
                    ], (string) $row['id'], (string) ($keyToChannel[$row['channel']] ?? $row['channel']));
                }

                return $out;
            }

            if (microtime(true) >= $deadline) {
                return [];
            }

            usleep(min($this->pollMs, max(1, (int) (($deadline - microtime(true)) * 1000))) * 1000);
        } while (true);
    }

    public function latestCursor(array $channels): Cursor
    {
        return new Cursor((string) ($this->query()->max('id') ?? 0));
    }

    public function supportsReplay(): bool
    {
        return true;
    }

    public function prune(int $retainMinutes): int
    {
        return $this->query()->where('created_at', '<', now()->subMinutes($retainMinutes))->delete();
    }

    private function query(): Builder
    {
        return $this->db->table($this->table);
    }

    private function key(string $channel): string
    {
        return $this->prefix.':'.$channel;
    }
}
