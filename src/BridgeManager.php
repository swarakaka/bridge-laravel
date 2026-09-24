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
use Bridge\Props\Once;
use Bridge\Props\PropResolver;
use Bridge\Props\Scroll;
use Bridge\Props\Serializer;
use Bridge\Representation\RepresenterRegistry;
use Bridge\Stream\ChannelAuthorizer;
use Bridge\Stream\Contracts\EventBus;
use Bridge\Stream\Publisher;
use Bridge\Stream\StreamResponse;
use Bridge\Stream\StreamWriter;
use Bridge\Support\Headers;
use Bridge\Support\Version;
use Closure;
use DateInterval;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The service behind the `Bridge\Bridge` facade. Holds shared props, the
 * build version and builds responses. Inject this class; the facade is for
 * static calls.
 */
class BridgeManager
{
    /** Request attribute holding a per-request `encryptHistory` decision. */
    public const ENCRYPT_HISTORY_ATTRIBUTE = 'bridge.history.encrypt';

    /** Request attribute set by clearHistory(); `sent` once a page carried it. */
    public const CLEAR_HISTORY_ATTRIBUTE = 'bridge.history.clear';

    /** Session key carrying a clearHistory() request across a redirect. */
    public const CLEAR_HISTORY_SESSION_KEY = 'bridge.clear_history';

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

    /**
     * An infinite-scroll list: a paginator (or a resource collection over one)
     * appended page by page, with `meta.scroll` describing both ends.
     */
    public function scroll(mixed $value, ?string $pageName = null): Scroll
    {
        return new Scroll($value, $pageName);
    }

    /** Merged key by key at every depth on opted-in partial reloads. */
    public function deepMerge(mixed $value): Merge
    {
        return new Merge($value, Merge::DEEP);
    }

    /**
     * Sent once, then reused by the client until `$ttl` (seconds or an
     * interval) passes. `$key` shares one value between props or pages.
     */
    public function once(mixed $value, ?string $key = null, DateInterval|int|null $ttl = null): Once
    {
        return new Once($value, $key, $ttl);
    }

    public function mode(?Request $request = null): Mode
    {
        $request ??= $this->container->make('request');

        return Negotiation::for($request)->mode;
    }

    /**
     * Store the pages of this request encrypted in the client's history
     * (spec/page.md §10). Overrides `bridge.history.encrypt` for the request.
     */
    public function encryptHistory(bool $encrypt = true, ?Request $request = null): void
    {
        $request ??= $this->container->make('request');
        $request->attributes->set(self::ENCRYPT_HISTORY_ATTRIBUTE, $encrypt);
    }

    /**
     * Ask the client to make the history entries it encrypted earlier
     * unreadable. Carried to the next page when this response redirects.
     */
    public function clearHistory(?Request $request = null): void
    {
        $request ??= $this->container->make('request');
        $request->attributes->set(self::CLEAR_HISTORY_ATTRIBUTE, true);
    }

    /**
     * The history members of a page's `meta` for this request. A pending
     * clear (from this request or carried in the session) is consumed.
     *
     * @return array{encryptHistory?: true, clearHistory?: true}
     */
    public function historyMeta(Request $request, ?bool $encrypt = null): array
    {
        $meta = [];

        $encrypt ??= $request->attributes->get(self::ENCRYPT_HISTORY_ATTRIBUTE);
        $encrypt ??= (bool) $this->container->make(Repository::class)->get('bridge.history.encrypt', false);

        if ($encrypt === true) {
            $meta['encryptHistory'] = true;
        }

        $clear = $request->attributes->get(self::CLEAR_HISTORY_ATTRIBUTE) === true;

        if ($request->hasSession() && $request->session()->pull(self::CLEAR_HISTORY_SESSION_KEY) === true) {
            $clear = true;
        }

        if ($clear) {
            $request->attributes->set(self::CLEAR_HISTORY_ATTRIBUTE, 'sent');
            $meta['clearHistory'] = true;
        }

        return $meta;
    }

    /**
     * @internal A clear requested during a redirecting request reaches the
     * client on the next page. Written after the controller ran, so a logout
     * that invalidated the session does not drop it.
     */
    public function carryClearHistory(Request $request, Response $response): void
    {
        if ($request->attributes->get(self::CLEAR_HISTORY_ATTRIBUTE) !== true || ! $request->hasSession()) {
            return;
        }

        if ($response->isRedirection() || ($response->getStatusCode() === 409 && $response->headers->has(Headers::LOCATION))) {
            $request->session()->put(self::CLEAR_HISTORY_SESSION_KEY, true);
        }
    }
}
