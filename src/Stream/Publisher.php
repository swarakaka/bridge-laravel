<?php

declare(strict_types=1);

namespace Bridge\Stream;

use Bridge\Props\Serializer;
use Bridge\Stream\Bus\Envelope;
use Bridge\Stream\Contracts\EventBus;
use Illuminate\Http\Request;

/**
 * Fluent publishing API: Bridge::to('customers')->invalidate('customers').
 */
final class Publisher
{
    /**
     * @param  list<string>  $channels
     */
    public function __construct(
        private readonly EventBus $bus,
        private readonly Serializer $serializer,
        private readonly Request $request,
        private readonly array $channels,
    ) {}

    /**
     * @param  list<string>|string  $keys
     */
    public function invalidate(array|string $keys): string
    {
        return $this->message(StreamMessage::invalidate($keys));
    }

    public function prop(string $key, mixed $value, string $mode = 'replace'): string
    {
        return $this->message(StreamMessage::prop($key, $this->serializer->serialize($value, $this->request), $mode));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function notify(string $message, string $level = 'info', ?string $title = null, array $meta = []): string
    {
        return $this->message(StreamMessage::notification($message, $level, $title, $meta));
    }

    public function navigate(string $url, bool $replace = false): string
    {
        return $this->message(StreamMessage::navigate($url, $replace));
    }

    public function progress(string $id, ?float $value, ?string $label = null): string
    {
        return $this->message(StreamMessage::progress($id, $value, $label));
    }

    /** Ask every subscriber's connection to close (they reconnect when $reconnect is true). */
    public function end(string $reason = 'closed', bool $reconnect = true): string
    {
        return $this->message(StreamMessage::end($reason, $reconnect));
    }

    public function event(string $name, mixed $payload = []): string
    {
        $data = $this->serializer->serialize($payload, $this->request);

        return $this->message(StreamMessage::event($name, is_array($data) ? $data : ['value' => $data]));
    }

    public function message(StreamMessage $message): string
    {
        return $this->bus->publish($this->channels, Envelope::make($message->event, $message->data));
    }
}
