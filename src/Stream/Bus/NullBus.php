<?php

declare(strict_types=1);

namespace Bridge\Stream\Bus;

use Bridge\Stream\Contracts\EventBus;

final class NullBus implements EventBus
{
    public function publish(array $channels, Envelope $envelope): string
    {
        return '0';
    }

    public function read(array $channels, Cursor $since, int $blockMs): iterable
    {
        if ($blockMs > 0) {
            usleep(min($blockMs, 250) * 1000);
        }

        return [];
    }

    public function latestCursor(array $channels): Cursor
    {
        return new Cursor('0');
    }

    public function supportsReplay(): bool
    {
        return false;
    }
}
