<?php

declare(strict_types=1);

namespace Bridge\Stream;

use Bridge\Negotiation\Mode;
use Bridge\Negotiation\Negotiation;
use Bridge\Negotiation\NotAcceptableException;
use Bridge\Props\Serializer;
use Bridge\Stream\Bus\Cursor;
use Bridge\Stream\Bus\Envelope;
use Bridge\Stream\Contracts\EventBus;
use Bridge\Support\Headers;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Returned by Bridge::stream(). Either subscribes to bus channels for the
 * connection's lifetime or runs a one-off producer callback (PLAN §20.3).
 */
final class StreamResponse implements Responsable
{
    /** @var list<string>|Closure|null */
    private array|Closure|null $channels = null;

    /** @var (callable(StreamWriter): void)|null */
    private $producer = null;

    private ?int $heartbeatMs = null;

    private ?int $maxDurationS = null;

    private ?int $retryMs = null;

    private bool $allowClientChannels = true;

    /** @var (callable(): float)|null */
    private $clock = null;

    /** @var (callable(string): void)|null */
    private $output = null;

    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
        private readonly EventBus $bus,
        private readonly ChannelAuthorizer $authorizer,
        private readonly ConnectionLimiter $limiter,
        private readonly Serializer $serializer,
    ) {}

    /**
     * Channels this connection subscribes to. A closure receives the user
     * (and any other container-resolvable dependency) and returns the list.
     *
     * @param  list<string>|Closure  $channels
     */
    public function channels(array|Closure $channels): self
    {
        $this->channels = $channels;

        return $this;
    }

    /**
     * @param  callable(StreamWriter): void  $producer
     */
    public function using(callable $producer): self
    {
        $this->producer = $producer;

        return $this;
    }

    public function heartbeat(int $ms): self
    {
        $this->heartbeatMs = $ms;

        return $this;
    }

    /** Seconds; 0 means "drain once and end" (useful in tests). */
    public function maxDuration(int $seconds): self
    {
        $this->maxDurationS = $seconds;

        return $this;
    }

    public function retry(int $ms): self
    {
        $this->retryMs = $ms;

        return $this;
    }

    /** Disallow `?channels=` from the client entirely. */
    public function serverChannelsOnly(): self
    {
        $this->allowClientChannels = false;

        return $this;
    }

    /**
     * @internal testing hook: a clock returning seconds as float
     *
     * @param  callable(): float  $clock
     */
    public function clock(callable $clock): self
    {
        $this->clock = $clock;

        return $this;
    }

    /**
     * @internal testing hook: capture output instead of echoing
     *
     * @param  callable(string): void  $output
     */
    public function output(callable $output): self
    {
        $this->output = $output;

        return $this;
    }

    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        $negotiation = Negotiation::for($request);

        if ($negotiation->mode !== Mode::Stream) {
            throw new NotAcceptableException(Mode::Stream->mediaTypes());
        }

        $heartbeat = $this->heartbeatMs ?? (int) $this->config->get('bridge.stream.heartbeat_ms', 15000);
        $maxDuration = $this->maxDurationS ?? $this->defaultMaxDuration();
        $retry = $this->retryMs ?? (int) $this->config->get('bridge.stream.retry_ms', 3000);
        $subject = $this->subject($request);
        $protocol = (int) $this->config->get('bridge.protocol.max_version', 1);

        $response = new StreamedResponse(null, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);

        if ($request->server->get('SERVER_PROTOCOL') === 'HTTP/1.1' || $request->server->get('SERVER_PROTOCOL') === 'HTTP/1.0') {
            $response->headers->set('Connection', 'keep-alive');
        }

        $response->setCallback(function () use ($request, $heartbeat, $maxDuration, $retry, $subject, $protocol): void {
            $this->prepareOutput();

            $writer = new StreamWriter($this->serializer, $request, $this->output, $this->output === null);

            $released = false;
            $release = function () use (&$released, $subject): void {
                if (! $released) {
                    $released = true;
                    $this->limiter->release($subject);
                }
            };

            if (! $this->limiter->acquire($subject)) {
                $writer->retry(max($retry, 5000));
                $writer->control(StreamMessage::ready($protocol, false, $heartbeat, null));
                $writer->control(StreamMessage::error(429, 'throttled', 'Too many open streams.', false));
                $writer->end('closed', true);

                return;
            }

            // Backstop for fatal errors or exit(): the shutdown function still runs.
            register_shutdown_function($release);

            try {
                $writer->retry($retry);

                if ($this->producer !== null) {
                    $writer->control(StreamMessage::ready($protocol, false, $heartbeat, null));
                    ($this->producer)($writer);
                    $writer->end('closed', false);

                    return;
                }

                $this->subscribe($request, $writer, $heartbeat, $maxDuration, $protocol);
            } finally {
                $release();
            }
        });

        return $response;
    }

    private function subscribe(Request $request, StreamWriter $writer, int $heartbeat, int $maxDuration, int $protocol): void
    {
        $channels = $this->resolveChannels($request);
        $requested = $this->requestedChannels($request);
        $user = $request->user();

        foreach ($requested as $channel) {
            if (! $this->authorizer->authorize($user, $channel)) {
                $writer->control(StreamMessage::ready($protocol, false, $heartbeat, $maxDuration * 1000));
                $writer->control(StreamMessage::error(403, 'forbidden', "Channel [{$channel}] is not authorized.", true));
                $writer->end('unauthorized', false);

                return;
            }

            $channels[] = $channel;
        }

        $channels = array_values(array_unique($channels));
        $lastEventId = $request->headers->get(Headers::LAST_EVENT_ID);
        $replay = $lastEventId !== null && $lastEventId !== '' && $this->bus->supportsReplay();
        $live = $this->bus->latestCursor($channels);
        $cursor = $replay ? Cursor::fromLastEventId((string) $lastEventId) : $live;

        $writer->control(StreamMessage::ready($protocol, $replay, $heartbeat, $maxDuration * 1000));

        if ($channels === []) {
            $writer->end('closed', false);

            return;
        }

        $now = $this->clock ?? static fn (): float => microtime(true);
        $started = $now();
        $deadline = $started + $maxDuration;
        $seen = [];

        while (true) {
            $remainingMs = (int) (($deadline - $now()) * 1000);
            $untilHeartbeatMs = $heartbeat - $writer->idleMs();
            $block = max(0, min($remainingMs, $untilHeartbeatMs));

            $envelopes = $this->bus->read($channels, $cursor, $block);

            foreach ($envelopes as $envelope) {
                $cursor = $cursor->advance((string) $envelope->channel, (string) $envelope->id);

                if (isset($seen[$envelope->uuid])) {
                    continue;
                }

                $seen[$envelope->uuid] = true;

                if (count($seen) > 512) {
                    $seen = array_slice($seen, -256, null, true);
                }

                if ($this->isEndSignal($envelope)) {
                    // An `end` only applies to connections that were live when it was
                    // published; replayed ones would close a fresh connection.
                    if ($this->isHistorical($envelope, $live)) {
                        continue;
                    }

                    $writer->control(StreamMessage::end(
                        (string) ($envelope->data['reason'] ?? 'closed'),
                        (bool) ($envelope->data['reconnect'] ?? true),
                    ), (string) $envelope->id);

                    return;
                }

                $writer->event($envelope->event, $envelope->data, (string) $envelope->id);
            }

            if ($writer->aborted()) {
                return;
            }

            if ($now() >= $deadline) {
                $writer->end('max_duration', true);

                return;
            }

            if ($writer->idleMs() >= $heartbeat) {
                $writer->heartbeat();
            }
        }
    }

    /**
     * @return list<string>
     */
    private function resolveChannels(Request $request): array
    {
        if ($this->channels === null) {
            return [];
        }

        if ($this->channels instanceof Closure) {
            $result = $this->container->call($this->channels, ['user' => $request->user(), 'request' => $request]);

            return array_values(array_map('strval', is_array($result) ? $result : []));
        }

        return $this->channels;
    }

    /**
     * @return list<string>
     */
    private function requestedChannels(Request $request): array
    {
        if (! $this->allowClientChannels) {
            return [];
        }

        $raw = $request->query('channels');

        if (is_array($raw)) {
            $raw = implode(',', array_map('strval', $raw));
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $c) => $c !== ''));
    }

    private function isEndSignal(Envelope $envelope): bool
    {
        return $envelope->isControl() && ($envelope->data['type'] ?? null) === 'end';
    }

    private function isHistorical(Envelope $envelope, Cursor $live): bool
    {
        $position = $live->for((string) $envelope->channel);

        return $position !== null && $envelope->id !== null && Cursor::compare($envelope->id, $position) <= 0;
    }

    private function subject(Request $request): string
    {
        $user = $request->user();

        if ($user instanceof Authenticatable) {
            return 'user:'.$user->getAuthIdentifier();
        }

        return 'ip:'.($request->ip() ?? 'unknown');
    }

    private function defaultMaxDuration(): int
    {
        $configured = $this->config->get('bridge.stream.max_duration_s');

        if (is_numeric($configured)) {
            return (int) $configured;
        }

        $octane = $this->container->bound('octane') || getenv('LARAVEL_OCTANE') !== false;

        return $octane ? 300 : 60;
    }

    private function prepareOutput(): void
    {
        if ($this->output !== null) {
            return;
        }

        @ini_set('zlib.output_compression', '0');
        @set_time_limit(0);
        // Keep running after a client abort so the loop notices it via
        // connection_aborted() and `finally` blocks (limiter release) execute.
        ignore_user_abort(true);
    }
}
