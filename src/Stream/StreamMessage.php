<?php

declare(strict_types=1);

namespace Bridge\Stream;

use Bridge\Errors\ErrorKind;

/**
 * A message on the wire: `bridge` control messages or an application event.
 * Factories mirror packages/protocol/spec/stream.md §3.
 */
final class StreamMessage
{
    public const CONTROL = 'bridge';

    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        public readonly string $event,
        public readonly array $data,
    ) {}

    public static function ready(int $protocol, bool $replayed, int $heartbeatMs, ?int $maxDurationMs): self
    {
        return self::control(['type' => 'ready', 'protocol' => $protocol, 'replayed' => $replayed, 'heartbeat' => $heartbeatMs, 'maxDuration' => $maxDurationMs]);
    }

    /**
     * @param  list<string>|string  $keys  prop keys or "*"
     */
    public static function invalidate(array|string $keys): self
    {
        return self::control(['type' => 'invalidate', 'keys' => $keys === '*' ? '*' : (is_array($keys) ? $keys : [$keys])]);
    }

    public static function prop(string $key, mixed $value, string $mode = 'replace'): self
    {
        return self::control(['type' => 'prop', 'key' => $key, 'value' => $value, 'mode' => $mode]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function notification(string $message, string $level = 'info', ?string $title = null, array $meta = []): self
    {
        $data = ['type' => 'notification', 'level' => $level, 'title' => $title, 'message' => $message];

        if ($meta !== []) {
            $data['meta'] = $meta;
        }

        return self::control($data);
    }

    public static function navigate(string $url, bool $replace = false): self
    {
        return self::control(['type' => 'navigate', 'url' => $url, 'replace' => $replace]);
    }

    public static function progress(string $id, ?float $value, ?string $label = null): self
    {
        return self::control(['type' => 'progress', 'id' => $id, 'value' => $value, 'label' => $label]);
    }

    public static function error(int $status, ErrorKind|string $kind, string $message, bool $final = true): self
    {
        return self::control(['type' => 'error', 'status' => $status, 'kind' => $kind instanceof ErrorKind ? $kind->value : $kind, 'message' => $message, 'final' => $final]);
    }

    public static function end(string $reason = 'closed', bool $reconnect = false): self
    {
        return self::control(['type' => 'end', 'reason' => $reason, 'reconnect' => $reconnect]);
    }

    /**
     * An application event. Names should be dot-separated lower-case.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function event(string $name, array $payload = []): self
    {
        if ($name === self::CONTROL) {
            throw new \InvalidArgumentException('"bridge" is reserved for control messages.');
        }

        return new self($name, $payload);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function control(array $data): self
    {
        return new self(self::CONTROL, $data);
    }

    public function isControl(): bool
    {
        return $this->event === self::CONTROL;
    }

    public function type(): ?string
    {
        return $this->isControl() && is_string($this->data['type'] ?? null) ? $this->data['type'] : null;
    }
}
