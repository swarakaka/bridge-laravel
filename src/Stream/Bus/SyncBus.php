<?php

declare(strict_types=1);

namespace Bridge\Stream\Bus;

use Bridge\Stream\Contracts\EventBus;

/**
 * In-process bus for tests and request-scoped producers. Not shared across processes.
 */
final class SyncBus implements EventBus
{
    /** @var list<Envelope> */
    private array $log = [];

    private int $sequence = 0;

    public function publish(array $channels, Envelope $envelope): string
    {
        $id = (string) ++$this->sequence;

        foreach ($channels as $channel) {
            $this->log[] = $envelope->withDelivery($id, $channel);
        }

        return $id;
    }

    public function read(array $channels, Cursor $since, int $blockMs): iterable
    {
        $found = [];

        foreach ($this->log as $entry) {
            if (! in_array($entry->channel, $channels, true)) {
                continue;
            }

            $position = $since->for((string) $entry->channel);

            if ($position !== null && Cursor::compare((string) $entry->id, $position) <= 0) {
                continue;
            }

            $found[] = $entry;
        }

        if ($found === [] && $blockMs > 0) {
            usleep(min($blockMs, 100) * 1000);
        }

        return $found;
    }

    public function latestCursor(array $channels): Cursor
    {
        return new Cursor((string) $this->sequence);
    }

    public function supportsReplay(): bool
    {
        return true;
    }

    /**
     * @return list<Envelope>
     */
    public function all(): array
    {
        return $this->log;
    }

    public function flush(): void
    {
        $this->log = [];
        $this->sequence = 0;
    }
}
