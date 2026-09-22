<?php

declare(strict_types=1);

namespace Bridge\Stream\Listeners;

use Bridge\Stream\Bus\Envelope;
use Bridge\Stream\Contracts\EventBus;
use Bridge\Stream\Contracts\ShouldStream;

/**
 * Wildcard listener: any dispatched event implementing ShouldStream is published.
 */
final class PublishStreamableEvents
{
    public function __construct(private readonly EventBus $bus) {}

    /**
     * @param  array<int, mixed>  $payload
     */
    public function handle(string $eventName, array $payload): void
    {
        $event = $payload[0] ?? null;

        if (! $event instanceof ShouldStream) {
            return;
        }

        $channels = array_map('strval', $event->streamOn());

        if ($channels === []) {
            return;
        }

        $message = $event->toStream();
        $this->bus->publish($channels, Envelope::make($message->event, $message->data));
    }
}
