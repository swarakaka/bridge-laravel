<?php

declare(strict_types=1);

namespace Bridge\Stream\Listeners;

use Bridge\Stream\Bus\Envelope;
use Bridge\Stream\Contracts\EventBus;
use Bridge\Stream\Contracts\ShouldStream;

/**
 * Publishes every dispatched event implementing ShouldStream. Registered on the
 * interface, which Laravel's dispatcher matches for object events, so it is not
 * built for every other event the application fires.
 */
final class PublishStreamableEvents
{
    public function __construct(private readonly EventBus $bus) {}

    public function handle(ShouldStream $event): void
    {
        $channels = array_map('strval', $event->streamOn());

        if ($channels === []) {
            return;
        }

        $messages = $event->toStream();

        foreach (is_array($messages) ? $messages : [$messages] as $message) {
            $this->bus->publish($channels, Envelope::make($message->event, $message->data));
        }
    }
}
