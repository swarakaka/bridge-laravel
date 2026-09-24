<?php

declare(strict_types=1);

namespace Bridge\Stream\Bus;

use Bridge\Stream\Contracts\EventBus;
use Bridge\Stream\Contracts\ReplayWindow;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Polling bus on a database table. Works everywhere; latency = poll interval.
 *
 * Auto-increment ids are allocated at insert but become visible at commit, so
 * on MySQL or PostgreSQL row 10 can appear after row 11 has been read. Each
 * read therefore also looks back `lookback` ids for rows the cursor has not
 * delivered yet and returns them first.
 */
final class DatabaseBus implements EventBus, ReplayWindow
{
    private readonly int $lookback;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly string $table = 'bridge_stream_events',
        private readonly int $pollMs = 1000,
        private readonly string $prefix = 'bridge',
        int $lookback = 200,
    ) {
        $this->lookback = max(0, min($lookback, Cursor::RECENT_LIMIT));
    }

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
        $after = self::position($since->fallback);
        $floor = self::position($since->floor);

        do {
            $rows = [
                ...$this->lateRows($keys, $since, max($floor, $after - $this->lookback), $after),
                ...$this->query()
                    ->whereIn('channel', $keys)
                    ->where('id', '>', $after)
                    ->orderBy('id')
                    ->limit(200)
                    ->get()
                    ->all(),
            ];

            if ($rows !== []) {
                return array_map(function (mixed $row) use ($keyToChannel): Envelope {
                    $row = (array) $row;

                    return Envelope::fromArray([
                        'uuid' => $row['uuid'],
                        'event' => $row['event'],
                        'data' => json_decode((string) $row['data'], true) ?: [],
                    ], (string) $row['id'], (string) ($keyToChannel[$row['channel']] ?? $row['channel']));
                }, $rows);
            }

            if (microtime(true) >= $deadline) {
                return [];
            }

            usleep(max(1, min($this->pollMs, (int) (($deadline - microtime(true)) * 1000))) * 1000);
        } while (true);
    }

    public function canReplayFrom(array $channels, string $lastEventId): bool
    {
        if (! ctype_digit($lastEventId)) {
            return false;
        }

        $after = (int) $lastEventId;
        $bounds = (array) $this->query()->selectRaw('min(id) as lo, max(id) as hi')->first();

        if (($bounds['hi'] ?? null) === null) {
            // Nothing stored: only "from the beginning" is certainly complete.
            return $after === 0;
        }

        // Pruned rows below the oldest one kept, or an id this table never issued.
        return (int) $bounds['lo'] <= $after + 1 && $after <= (int) $bounds['hi'];
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

    /**
     * Rows in ($from, $to] that became visible after the cursor moved past them.
     *
     * @param  list<string>  $keys
     * @return array<int, object>
     */
    private function lateRows(array $keys, Cursor $since, int $from, int $to): array
    {
        if ($from >= $to) {
            return [];
        }

        $delivered = array_flip($since->recent);
        $missing = $this->query()
            ->whereIn('channel', $keys)
            ->where('id', '>', $from)
            ->where('id', '<=', $to)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->reject(fn (string $id): bool => isset($delivered[$id]))
            ->values()
            ->all();

        if ($missing === []) {
            return [];
        }

        return $this->query()->whereIn('id', $missing)->orderBy('id')->get()->all();
    }

    private static function position(?string $id): int
    {
        return $id !== null && ctype_digit($id) ? (int) $id : 0;
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
