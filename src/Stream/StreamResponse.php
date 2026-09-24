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
use Bridge\Stream\Contracts\ReplayWindow;
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

        // At least 100 ms: the heartbeat bounds each blocking bus read, and 0 would spin.
        $heartbeat = max(100, $this->heartbeatMs ?? (int) $this->config->get('bridge.stream.heartbeat_ms', 15000));
        $maxDuration = $this->maxDurationS ?? $this->defaultMaxDuration();
        $retry = max(0, $this->retryMs ?? (int) $this->config->get('bridge.stream.retry_ms', 3000));
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

            // Subscriptions renew a short lease on every heartbeat, so a killed worker's
            // slot frees itself quickly; producers send no heartbeats and keep the
            // lease for the whole stream.
            $leaseSeconds = $this->producer === null ? self::leaseSeconds($heartbeat) : null;
            $lease = $this->limiter->acquire($subject, $leaseSeconds);

            $released = false;
            $release = function () use (&$released, $subject, $lease): void {
                if (! $released) {
                    $released = true;
                    $this->limiter->release($subject, $lease);
                }
            };

            if ($lease === null) {
                $writer->retry(max($retry, 5000));
                $writer->control(StreamMessage::ready($protocol, false, $heartbeat, null));
                // No `end`: that asks for an immediate reconnect (spec §6.2). Closing
                // after a non-final error makes the client back off, starting at `retry`.
                $writer->control(StreamMessage::error(429, 'throttled', 'Too many open streams.', false));

                return;
            }

            // Backstop for fatal errors or exit(), where `finally` does not run.
            $slot = self::holdSlot($release);

            try {
                $writer->retry($retry);

                if ($this->producer !== null) {
                    $writer->control(StreamMessage::ready($protocol, false, $heartbeat, null));
                    ($this->producer)($writer);
                    $writer->end('closed', false);

                    return;
                }

                $renew = fn () => $this->limiter->renew($subject, $lease, (int) $leaseSeconds);
                $this->subscribe($request, $writer, $heartbeat, $maxDuration, $protocol, $renew);
            } finally {
                $release();
                unset(self::$openSlots[$slot]);
            }
        });

        return $response;
    }

    /**
     * Releases of the streams open in this process. One shutdown function for
     * the whole process: a function per stream would pile up in long-lived
     * workers (Octane), where shutdown functions only run when the worker exits.
     *
     * @var array<int, Closure(): void>
     */
    private static array $openSlots = [];

    private static bool $shutdownRegistered = false;

    private static int $nextSlot = 0;

    /**
     * @param  Closure(): void  $release
     */
    private static function holdSlot(Closure $release): int
    {
        if (! self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(static function (): void {
                foreach (self::$openSlots as $pending) {
                    $pending();
                }
                self::$openSlots = [];
            });
        }

        $slot = ++self::$nextSlot;
        self::$openSlots[$slot] = $release;

        return $slot;
    }

    /** @internal for tests */
    public static function openSlotCount(): int
    {
        return count(self::$openSlots);
    }

    /** Three heartbeats and a margin: long enough to survive one slow iteration. */
    private static function leaseSeconds(int $heartbeatMs): int
    {
        return (int) ceil($heartbeatMs * 3 / 1000) + 5;
    }

    /**
     * @param  Closure(): void  $renew
     */
    private function subscribe(Request $request, StreamWriter $writer, int $heartbeat, int $maxDuration, int $protocol, Closure $renew): void
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

        if ($lastEventId === null || $lastEventId === '') {
            $query = $request->query(Headers::LAST_EVENT_ID_QUERY);
            $lastEventId = is_string($query) ? $query : null;
        }
        $replay = $lastEventId !== null && $lastEventId !== '' && $this->bus->supportsReplay()
            && (! $this->bus instanceof ReplayWindow || $this->bus->canReplayFrom($channels, $lastEventId));
        $live = $this->bus->latestCursor($channels);
        $cursor = $replay ? Cursor::fromLastEventId((string) $lastEventId) : $live;

        $writer->control(StreamMessage::ready($protocol, $replay, $heartbeat, $maxDuration * 1000));

        if ($channels === []) {
            $writer->end('closed', false);

            return;
        }

        $now = $this->clock ?? static fn (): float => microtime(true);
        $started = $now();
        $renewedAt = $started;
        $deadline = $started + $maxDuration;
        $seen = [];

        while (true) {
            $remainingMs = (int) (($deadline - $now()) * 1000);
            $untilHeartbeatMs = $heartbeat - $writer->idleMs();
            $block = max(0, min($remainingMs, $untilHeartbeatMs));

            $envelopes = $this->bus->read($channels, $cursor, $block);

            foreach ($envelopes as $envelope) {
                // A row that became visible after later ones goes out without an id,
                // so the client's Last-Event-ID never moves backwards (spec/stream.md §5).
                $late = $cursor->isBehind((string) $envelope->id);
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

                $writer->event($envelope->event, $envelope->data, $late ? null : (string) $envelope->id);
            }

            if ($writer->aborted()) {
                return;
            }

            if ($now() >= $deadline) {
                // The cursor as id lets the reconnect replay whatever is published in
                // the gap, even when this connection delivered no events (spec §5).
                $writer->end('max_duration', true, $this->bus->supportsReplay() ? $cursor->fallback : null);

                return;
            }

            if ($writer->idleMs() >= $heartbeat) {
                $writer->heartbeat();
            }

            if ($now() - $renewedAt >= $heartbeat / 1000) {
                $renew();
                $renewedAt = $now();
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

        $channels = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $c) => $c !== ''));

        // Caps: a client may not request unbounded or oversized channel names.
        $max = (int) $this->config->get('bridge.stream.max_client_channels', 20);

        return array_values(array_filter(array_slice($channels, 0, max(0, $max)), static fn (string $c) => strlen($c) <= 190));
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
