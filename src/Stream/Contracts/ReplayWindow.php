<?php

declare(strict_types=1);

namespace Bridge\Stream\Contracts;

/**
 * Optional for replaying buses: reports whether retention (pruning, trimming)
 * may have dropped events after a client's Last-Event-ID. When it has, the
 * connection answers `replayed: false` so the client resyncs (spec/stream.md §5).
 */
interface ReplayWindow
{
    /**
     * Whether every event on $channels after $lastEventId is still stored.
     * Return false for ids this bus did not issue or cannot interpret.
     *
     * @param  list<string>  $channels
     */
    public function canReplayFrom(array $channels, string $lastEventId): bool;
}
