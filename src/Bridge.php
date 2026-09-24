<?php

declare(strict_types=1);

namespace Bridge;

use Bridge\Http\Middleware\AuthenticateStreamTicket;
use Bridge\Http\Responses\PageResponse;
use Bridge\Http\Responses\RedirectBuilder;
use Bridge\Negotiation\Mode;
use Bridge\Negotiation\Negotiation;
use Bridge\Page\Page;
use Bridge\Props\Always;
use Bridge\Props\Deferred;
use Bridge\Props\Lazy;
use Bridge\Props\Merge;
use Bridge\Props\PropResolver;
use Bridge\Props\Serializer;
use Bridge\Representation\RepresenterRegistry;
use Bridge\Stream\ChannelAuthorizer;
use Bridge\Stream\Contracts\EventBus;
use Bridge\Stream\Publisher;
use Bridge\Stream\StreamResponse;
use Bridge\Stream\StreamWriter;
use Bridge\Support\Version;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;

/**
 * Facade root. Holds shared props, the build version and builds responses.
 */
class Bridge
{
    /** @var array<string, mixed> */
    private array $shared = [];

    /**
     * Shares registered while the application booted. Everything shared later
     * (middleware, controllers) belongs to one request and is dropped after it,
     * so long-lived workers (Octane) never leak one user's props to the next.
     *
     * @var array<string, mixed>|null
     */
    private ?array $bootShared = null;

    public function __construct(
        private readonly Container $container,
        private readonly Version $version,
    ) {}

    /**
     * @param  array<string, mixed>  $props
     */
    public function render(string $component, array $props = []): PageResponse
    {
        return new PageResponse(
            new Page($component, $props),
            $this,
            $this->container->make(PropResolver::class),
            $this->container->make(RepresenterRegistry::class),
        );
    }

    public function redirect(): RedirectBuilder
    {
        return $this->container->make(RedirectBuilder::class);
    }

    /**
     * A stream response: subscribe with ->channels([...]) or run a one-off
     * producer callback (Bridge::stream(fn (StreamWriter $s) => ...)).
     *
     * @param  (callable(StreamWriter): void)|null  $producer
     */
    public function stream(?callable $producer = null): StreamResponse
    {
        $response = $this->container->make(StreamResponse::class);

        return $producer === null ? $response : $response->using($producer);
    }

    /**
     * Publish to channels: Bridge::to('customers')->invalidate('customers').
     *
     * @param  string|list<string>  $channels
     */
    public function to(string|array $channels): Publisher
    {
        return new Publisher(
            $this->container->make(EventBus::class),
            $this->container->make(Serializer::class),
            $this->container->make('request'),
            is_array($channels) ? $channels : [$channels],
        );
    }

    /**
     * Authorize client-requested channels, Broadcast::channel style.
     */
    public function channel(string $pattern, Closure $callback): void
    {
        $this->container->make(ChannelAuthorizer::class)->register($pattern, $callback);
    }

    /**
     * A short-lived signed URL to a stream route, for clients that cannot send
     * headers (native EventSource with token auth). The route must use the
     * `bridge.ticket` middleware.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function streamTicket(string $routeName, array $parameters = [], ?int $ttlSeconds = null): string
    {
        $user = $this->container->make(AuthFactory::class)->guard()->user();

        if ($user === null) {
            throw new \RuntimeException('Stream tickets require an authenticated user.');
        }

        $ttl = $ttlSeconds ?? (int) $this->container->make('config')->get('bridge.stream.ticket_ttl_s', 60);

        return $this->container->make(UrlGenerator::class)->temporarySignedRoute(
            $routeName,
            now()->addSeconds($ttl),
            [AuthenticateStreamTicket::USER_PARAMETER => $user->getAuthIdentifier()] + $parameters,
        );
    }

    /**
     * @param  string|array<string, mixed>  $key
     */
    public function share(string|array $key, mixed $value = null): void
    {
        if (is_array($key)) {
            $this->shared = array_merge($this->shared, $key);

            return;
        }

        $this->shared[$key] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function shared(?string $key = null, mixed $default = null): mixed
    {
        if ($key !== null) {
            return $this->shared[$key] ?? $default;
        }

        return $this->shared;
    }

    public function flushShared(): void
    {
        $this->shared = [];
    }

    /** @internal Called once the application has booted. */
    public function freezeBootShared(): void
    {
        $this->bootShared = $this->shared;
    }

    /** @internal Called after every handled request. */
    public function resetRequestShared(): void
    {
        if ($this->bootShared !== null) {
            $this->shared = $this->bootShared;
        }
    }

    public function setVersion(Closure|string|null $version): void
    {
        $this->version->set($version);
    }

    public function version(): ?string
    {
        return $this->version->get();
    }

    public function lazy(Closure $callback): Lazy
    {
        return new Lazy($callback);
    }

    public function defer(Closure $callback, string $group = 'default'): Deferred
    {
        return new Deferred($callback, $group);
    }

    public function always(mixed $value): Always
    {
        return new Always($value);
    }

    /** Appended by clients on partial reloads (infinite scroll, "load more"). */
    public function merge(mixed $value): Merge
    {
        return new Merge($value);
    }

    public function mode(?Request $request = null): Mode
    {
        $request ??= $this->container->make('request');

        return Negotiation::for($request)->mode;
    }
}
