# swarakaka/bridge-laravel

Laravel package for the Bridge protocol: one controller action, served as an HTML shell, a Bridge page object, or a JSON API document depending on the request's `Accept` header, plus first-class server-sent event streams.

Requires PHP 8.2+ and Laravel 11, 12 or 13.

## Install

```bash
composer require swarakaka/bridge-laravel
php artisan bridge:install        # publishes config/bridge.php and resources/views/app.blade.php
```

The `bridge` middleware is appended to the `web` group automatically (`bridge.middleware.auto_register`). Set `bridge.shell.view` to `app` to use the published shell, or keep the package default `bridge::app`.

The shell marks where the client mounts, either with directives or with Blade components (identical output; the components accept attributes):

```blade
<head>
    <x-bridge::head />                      {{-- or @bridgeHead: protocol/build meta tags, SSR head fragments --}}
</head>
<body>
    <x-bridge::app class="h-full" />        {{-- or @bridge: the embedded page data block and <div id="app" data-bridge> --}}
</body>
```

`<x-bridge::app>` accepts `id` (default `bridge.shell.root_id`), `:page` (defaults to the current response's page; `false` for an empty root) and `:ssr-body`; `<x-bridge::head>` accepts `:protocol`, `build` and `:ssr-head`.

## Usage

```php
use Bridge\Facades\Bridge;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        return Bridge::render('Customers/Index', [
            'customers' => CustomerResource::collection(Customer::paginate(20)),
            'filters'   => ['search' => $request->string('search')->toString()],
            'stats'     => Bridge::defer(fn () => Customer::stats()),   // loaded after first render
            'filtersUi' => Bridge::lazy(fn () => Filter::all()),        // only on partial reload
        ]);
    }

    public function store(StoreCustomerRequest $request)
    {
        $customer = Customer::create($request->validated());

        return Bridge::redirect()
            ->route('customers.show', $customer)
            ->with('customer', CustomerResource::make($customer))
            ->flash('Customer created.')
            ->created();
    }
}
```

| Request                                    | `index()` returns                                                                         | `store()` returns                                                         |
| ------------------------------------------ | ----------------------------------------------------------------------------------------- | ------------------------------------------------------------------------- |
| `Accept: text/html`                        | HTML shell with the page object embedded in `#bridge-page`                                | `302` redirect                                                            |
| `Accept: application/vnd.bridge+json; v=1` | `{ "type": "page", "component": "Customers/Index", "url", "props", "build", "deferred" }` | `303` + `Location`                                                        |
| `Accept: application/json`                 | `{ "data": { "customers": {...}, "filters": {...}, "stats": {...} } }`                    | `201` + `Location` + `{ "data": {...}, "meta": { "location", "flash" } }` |
| `Accept: text/event-stream`                | `406` (rendering route)                                                                   | `406`                                                                     |

No mode-specific code exists in controllers. Validation and other exceptions are mapped centrally: `422` with a Bridge error object in page mode, Laravel-native `{ message, errors }` in JSON mode, Laravel's redirect-back in HTML mode.

### Props

- Plain values, closures (container-injected), `JsonResource`, `ResourceCollection` (paginated collections keep Laravel's `{data, links, meta}`), paginators, `Arrayable`, `JsonSerializable`, enums, dates.
- `Bridge::lazy(fn)` — excluded until named in `X-Bridge-Only`.
- `Bridge::defer(fn, group)` — excluded from the initial page and listed in `deferred[group]`; resolved inline in JSON mode.
- `Bridge::always(value)` — present in every response, including partial ones.
- `Bridge::merge(fn)` — listed in `meta.merge`; clients that opt in ("load more") append instead of replace.
- `Bridge::share(key, value)` — shared props for every page. `errors` and `flash` are shared by default.

### JSON root

`Bridge::render('Customers/Show', [...])->jsonRoot('customer')` makes that prop the `data` root in JSON mode and moves the other props to `meta`.

### Partial reloads

Send `X-Bridge-Only: customers,stats` (or `X-Bridge-Except`) with `X-Bridge-Component: Customers/Index`. Dot keys (`customers.data`) select nested values. A component mismatch returns a full page.

### Build version

`X-Bridge-Build` on GET page requests is compared with `Bridge::version()` (config `bridge.build.version`, `BRIDGE_BUILD_VERSION`, or the Vite manifest hash). A mismatch returns `409` with `X-Bridge-Location` so the client reloads.

### Caching

Page and JSON responses are `private, no-cache` with a weak `ETag` (`304` on `If-None-Match`). Opt in per response with `->cache(maxAge: 60, public: true)`; `public` is refused for authenticated users unless `force: true`.

### CSRF for bearer clients on web routes

Replace the CSRF middleware in the `web` group with `Bridge\Http\Middleware\VerifyCsrfToken`. It skips verification only for requests that carry `Authorization: Bearer …` **and no session cookie**, so cookie sessions keep full protection.

```php
// bootstrap/app.php
$middleware->replaceInGroup('web', PreventRequestForgery::class, \Bridge\Http\Middleware\VerifyCsrfToken::class); // Laravel 13
$middleware->replaceInGroup('web', ValidateCsrfToken::class, \Bridge\Http\Middleware\VerifyCsrfToken::class);     // Laravel 11/12
```

### Testing

`TestResponse` macros: `assertBridgePage($component, fn (AssertablePage $page) => ...)`, `assertBridgeProp($key, $value)`, `assertBridgeError($status, $kind)`, `assertJsonMode()`, `assertHtmlShell()`.

### Streams (SSE)

```php
// routes/web.php — one stream per user, subscribed to a shared and a private channel
Route::get('/events', fn () => Bridge::stream()->channels(fn ($user) => ['customers', "user.{$user->id}"]))
    ->middleware('auth:sanctum');

// Anywhere: controllers, jobs, listeners
Bridge::to('customers')->invalidate(['customers']);            // clients partial-reload these props
Bridge::to("user.{$id}")->notify('Saved', 'success');           // toast
Bridge::to("user.{$id}")->prop('unreadCount', 3);               // push a small prop
Bridge::to('customers')->event('customer.created', $resource);  // application event

// Or mark an event class, mirroring ShouldBroadcast
class CustomerCreated implements ShouldStream {
    public function streamOn(): array { return ['customers']; }
    public function toStream(): array { return [StreamMessage::event('customer.created', [...]), StreamMessage::invalidate(['customers'])]; }
}

// One-off producer streams
Route::post('/export', fn () => Bridge::stream(function (StreamWriter $s) {
    $s->progress('export', 0.5, 'Halfway');
    $s->emit('export.done', ['rows' => 10]);
}));

// Client-requested channels are authorized like broadcast channels
Bridge::channel('tenant.{id}', fn (User $user, string $id) => $user->tenant_id === (int) $id);
```

Bus drivers: `redis` (Redis Streams, replay with `Last-Event-ID`), `database` (polling, no Redis; use SQLite WAL), `sync`, `null`. Apply `throttle:bridge-stream` to stream routes (`bridge.stream.connects_per_minute`); client-requested `?channels=` are capped (`bridge.stream.max_client_channels`). Connections end after `max_duration_s` with `end{reconnect:true}` so workers recycle; heartbeats are `: hb` comments. `bridge:doctor` checks the runtime, `bridge:stream:prune` trims the database bus, `Bridge::streamTicket()` issues signed URLs for clients that cannot send headers. See the deployment guide (`docs/guide/streams-deployment.md`, published on the docs site).

### Server-side rendering

```dotenv
BRIDGE_SSR_ENABLED=true
BRIDGE_SSR_URL=http://127.0.0.1:13714
```

Build the SSR bundle with Vite (`vite build --ssr resources/js/ssr.ts --outDir bootstrap/ssr`) and run `php artisan bridge:ssr`. HTML requests POST the page object to the SSR server and embed its `{head, body}`; failures fall back to client rendering. **After upgrading, run `php artisan view:clear`** so the compiled `@bridge` directives pick up the SSR arguments.

## Protocol

The normative specification, JSON Schemas and golden fixtures live in `packages/protocol`. This package's conformance suite asserts it produces those fixtures exactly.
