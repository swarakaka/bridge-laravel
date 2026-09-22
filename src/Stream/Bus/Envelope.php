<?php

declare(strict_types=1);

namespace Bridge\Stream\Bus;

use Illuminate\Support\Str;

/**
 * One message on the bus. `event` is "bridge" for control messages or the
 * application event name; `data` is the JSON payload.
 */
final class Envelope
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $event,
        public readonly array $data,
        public readonly string $uuid,
        public readonly ?string $id = null,
        public readonly ?string $channel = null,
        public readonly ?float $publishedAt = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function make(string $event, array $data): self
    {
        return new self($event, $data, (string) Str::uuid(), null, null, microtime(true));
    }

    public function withDelivery(string $id, string $channel): self
    {
        return new self($this->event, $this->data, $this->uuid, $id, $channel, $this->publishedAt);
    }

    public function isControl(): bool
    {
        return $this->event === 'bridge';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'event' => $this->event,
            'data' => $this->data,
            'published_at' => $this->publishedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload, string $id, string $channel): self
    {
        return new self(
            (string) ($payload['event'] ?? 'bridge'),
            is_array($payload['data'] ?? null) ? $payload['data'] : [],
            (string) ($payload['uuid'] ?? Str::uuid()),
            $id,
            $channel,
            isset($payload['published_at']) ? (float) $payload['published_at'] : null,
        );
    }
}
