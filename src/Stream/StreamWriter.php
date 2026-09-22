<?php

declare(strict_types=1);

namespace Bridge\Stream;

use Bridge\Props\Serializer;
use Illuminate\Http\Request;

/**
 * Writes text/event-stream frames. Producers receive it from Bridge::stream(fn (StreamWriter $s) => ...).
 */
final class StreamWriter
{
    private float $lastWriteAt;

    private bool $ended = false;

    /** @var (callable(string): void)|null */
    private $output;

    /**
     * @param  (callable(string): void)|null  $output  defaults to echo + flush
     */
    public function __construct(
        private readonly Serializer $serializer,
        private readonly Request $request,
        ?callable $output = null,
        private readonly bool $checkAborted = true,
    ) {
        $this->output = $output;
        $this->lastWriteAt = microtime(true);
    }

    public function retry(int $ms): void
    {
        $this->raw("retry: {$ms}\n\n");
    }

    public function comment(string $text = 'hb'): void
    {
        $this->raw(": {$text}\n\n");
    }

    public function heartbeat(): void
    {
        $this->comment('hb');
    }

    /**
     * @param  array<string, mixed>|string  $data
     */
    public function event(string $name, array|string $data, ?string $id = null): void
    {
        $json = is_string($data) ? $data : json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $frame = '';

        if ($id !== null && $id !== '') {
            $frame .= 'id: '.str_replace(["\r", "\n"], '', $id)."\n";
        }

        $frame .= 'event: '.str_replace(["\r", "\n"], '', $name)."\n";
        $frame .= 'data: '.str_replace(["\r\n", "\r", "\n"], ' ', $json)."\n\n";

        $this->raw($frame);
    }

    public function control(StreamMessage $message, ?string $id = null): void
    {
        $this->event($message->event, $message->data, $id);
    }

    public function message(StreamMessage $message, ?string $id = null): void
    {
        $this->control($message, $id);
    }

    // Producer conveniences ---------------------------------------------------

    /**
     * @param  list<string>|string  $keys
     */
    public function invalidate(array|string $keys): void
    {
        $this->control(StreamMessage::invalidate($keys));
    }

    public function prop(string $key, mixed $value, string $mode = 'replace'): void
    {
        $this->control(StreamMessage::prop($key, $this->serializer->serialize($value, $this->request), $mode));
    }

    public function notify(string $message, string $level = 'info', ?string $title = null): void
    {
        $this->control(StreamMessage::notification($message, $level, $title));
    }

    public function navigate(string $url, bool $replace = false): void
    {
        $this->control(StreamMessage::navigate($url, $replace));
    }

    public function progress(string $id, ?float $value, ?string $label = null): void
    {
        $this->control(StreamMessage::progress($id, $value, $label));
    }

    public function emit(string $name, mixed $payload = []): void
    {
        $data = $this->serializer->serialize($payload, $this->request);
        $this->control(StreamMessage::event($name, is_array($data) ? $data : ['value' => $data]));
    }

    public function error(int $status, string $kind, string $message, bool $final = true): void
    {
        $this->control(StreamMessage::error($status, $kind, $message, $final));
    }

    public function end(string $reason = 'closed', bool $reconnect = false): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended = true;
        $this->control(StreamMessage::end($reason, $reconnect));
    }

    // Internals ---------------------------------------------------------------

    public function raw(string $chunk): void
    {
        if ($this->output !== null) {
            ($this->output)($chunk);
        } else {
            echo $chunk;

            if (ob_get_level() > 0) {
                ob_flush();
            }

            flush();
        }

        $this->lastWriteAt = microtime(true);
    }

    public function ended(): bool
    {
        return $this->ended;
    }

    public function aborted(): bool
    {
        return $this->checkAborted && connection_aborted() === 1;
    }

    /** Milliseconds since the last write. */
    public function idleMs(): int
    {
        return (int) ((microtime(true) - $this->lastWriteAt) * 1000);
    }
}
