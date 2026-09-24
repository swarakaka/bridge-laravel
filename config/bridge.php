<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Protocol
    |--------------------------------------------------------------------------
    | Highest Bridge protocol version this server speaks. Page requests asking
    | for a higher `v` receive 406 (packages/protocol/spec/negotiation.md §3).
    */
    'protocol' => [
        'max_version' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTML shell
    |--------------------------------------------------------------------------
    | `view`  : Blade view rendered for text/html requests. Use @bridgeHead and
    |           @bridge inside it. Publish with `bridge:install` to customise.
    | `embed` : embed the initial page object in the shell (default). Set to
    |           false for a static, CDN-cacheable shell; the client then
    |           bootstraps with a page request.
    */
    'shell' => [
        'view' => 'bridge::app',
        'embed' => true,
        'root_id' => 'app',
    ],

    /*
    |--------------------------------------------------------------------------
    | Asset build
    |--------------------------------------------------------------------------
    | Compared with X-Bridge-Build on GET page requests; mismatch → 409 and a
    | full reload. Null derives it from the Vite manifest hash.
    */
    'build' => [
        'version' => env('BRIDGE_BUILD_VERSION'),
        'manifest' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Negotiation
    |--------------------------------------------------------------------------
    | Mode used when the request only matches through a wildcard (star/star).
    | Override per route with ->defaults('bridge.default_mode', 'json').
    */
    'negotiation' => [
        'default_mode' => 'html',
    ],

    'json' => [
        // Resolve deferred props inline for JSON clients (they have no post-render phase).
        'resolve_deferred' => true,
    ],

    'auth' => [
        // `redirect` hint on unauthenticated errors when the exception has none.
        'login_url' => '/login',
    ],

    'flash' => [
        // Session keys exposed as the `flash` shared prop; the first is the message, the second the level.
        'keys' => ['message', 'level'],
    ],

    'cache' => [
        // Weak ETag + 304 handling on GET page/JSON responses.
        'etag' => true,
    ],

    'csrf' => [
        // Only affects Bridge\Http\Middleware\VerifyCsrfToken when you use it.
        'skip_for_bearer' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Server-side rendering
    |--------------------------------------------------------------------------
    | When enabled, HTML requests POST the page object to the SSR server
    | (`@swarakaka/bridge-vue/server`, started with `node bootstrap/ssr/ssr.js`
    | or `php artisan bridge:ssr`). Failures fall back to client rendering.
    */
    'ssr' => [
        'enabled' => (bool) env('BRIDGE_SSR_ENABLED', false),
        'url' => env('BRIDGE_SSR_URL', 'http://127.0.0.1:13714'),
        'timeout' => (float) env('BRIDGE_SSR_TIMEOUT', 2.0),
        // After a connection failure or timeout, skip SSR for this many seconds.
        'cooldown_s' => (int) env('BRIDGE_SSR_COOLDOWN', 10),
        'bundle' => env('BRIDGE_SSR_BUNDLE', 'bootstrap/ssr/ssr.js'),
    ],

    'middleware' => [
        // Push HandleBridgeRequests onto the `web` group automatically.
        'auto_register' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Streams (SSE)
    |--------------------------------------------------------------------------
    | driver         : sync (tests/single process), database (polling, no
    |                  Redis needed), redis (Redis Streams, recommended), null.
    | heartbeat_ms   : ": hb" comment interval while idle.
    | max_duration_s : connections end with `end{reconnect:true}` after this
    |                  many seconds so workers recycle (null → 60, or 300 on
    |                  Octane). Clients reconnect transparently.
    | ticket_ttl_s   : lifetime of signed stream ticket URLs.
    */
    'stream' => [
        'driver' => env('BRIDGE_STREAM_DRIVER', 'database'),
        'prefix' => env('BRIDGE_STREAM_PREFIX', env('APP_NAME', 'bridge')),
        'heartbeat_ms' => (int) env('BRIDGE_STREAM_HEARTBEAT_MS', 15000),
        'max_duration_s' => env('BRIDGE_STREAM_MAX_DURATION'),
        'retry_ms' => 3000,
        'max_connections_per_user' => (int) env('BRIDGE_STREAM_MAX_CONNECTIONS', 3),
        // `throttle:bridge-stream` limiter: connection attempts per minute per user or IP.
        'connects_per_minute' => (int) env('BRIDGE_STREAM_CONNECTS_PER_MINUTE', 30),
        // Maximum client-requested channels (`?channels=`) per connection.
        'max_client_channels' => 20,
        'ticket_ttl_s' => 60,
        'drivers' => [
            'sync' => [],
            'null' => [],
            // retain_minutes: a channel's stream key expires this long after its last publish.
            'redis' => ['connection' => env('BRIDGE_STREAM_REDIS_CONNECTION', 'default'), 'maxlen' => 1000, 'retain_minutes' => 60],
            'database' => [
                'connection' => env('BRIDGE_STREAM_DB_CONNECTION'),
                'table' => 'bridge_stream_events',
                'poll_ms' => (int) env('BRIDGE_STREAM_POLL_MS', 1000),
                // Ids re-checked behind the cursor for rows committed out of order.
                'lookback' => 200,
                'retain_minutes' => 60,
            ],
        ],
    ],

];
