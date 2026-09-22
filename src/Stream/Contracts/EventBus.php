<?php

declare(strict_types=1);

namespace Bridge\Stream\Contracts;

use Bridge\Stream\Bus\Cursor;
use Bridge\Stream\Bus\Envelope;

/**
 * Transport-agnostic publish/subscribe with an opaque, ordered cursor (PLAN §20.2).
 */
interface EventBus
{
    /**
     * Publish an envelope to each channel. Returns the assigned id.
     *
     * @param  list<string>  $channels
     */
    public function publish(array $channels, Envelope $envelope): string;

    /**
     * Read envelopes published after $since on any of $channels, blocking up
     * to $blockMs when the driver can. May return an empty iterable on timeout.
     *
     * @param  list<string>  $channels
     * @return iterable<Envelope>
     */
    public function read(array $channels, Cursor $since, int $blockMs): iterable;

    /**
     * A cursor positioned at "now": reading from it yields only future events.
     *
     * @param  list<string>  $channels
     */
    public function latestCursor(array $channels): Cursor;

    /** Whether read() can return events older than the connection (Last-Event-ID replay). */
    public function supportsReplay(): bool;
}
