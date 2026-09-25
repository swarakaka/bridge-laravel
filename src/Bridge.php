<?php

declare(strict_types=1);

namespace Bridge;

use Bridge\Http\Responses\PageResponse;
use Bridge\Http\Responses\RedirectBuilder;
use Bridge\Negotiation\Mode;
use Bridge\Props\Always;
use Bridge\Props\Deferred;
use Bridge\Props\Lazy;
use Bridge\Props\Merge;
use Bridge\Props\Once;
use Bridge\Props\Scroll;
use Bridge\Props\Watch;
use Bridge\Stream\Publisher;
use Bridge\Stream\StreamResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/**
 * The static entry point: `use Bridge\Bridge;` then `Bridge::render(...)`.
 * Calls go to the `BridgeManager` singleton; inject that class instead of this one.
 *
 * @method static PageResponse render(string $component, array<string, mixed> $props = [])
 * @method static RedirectBuilder redirect()
 * @method static StreamResponse stream(?callable $producer = null)
 * @method static Publisher to(string|list<string> $channels)
 * @method static void channel(string $pattern, Closure $callback)
 * @method static string streamTicket(string $routeName, array<string, mixed> $parameters = [], ?int $ttlSeconds = null)
 * @method static void share(string|array<string, mixed> $key, mixed $value = null)
 * @method static mixed shared(?string $key = null, mixed $default = null)
 * @method static void flushShared()
 * @method static void setVersion(Closure|string|null $version)
 * @method static ?string version()
 * @method static Lazy lazy(Closure $callback)
 * @method static Deferred defer(Closure $callback, string $group = 'default')
 * @method static Always always(mixed $value)
 * @method static Merge merge(mixed $value)
 * @method static Merge deepMerge(mixed $value)
 * @method static Scroll scroll(mixed $value, ?string $pageName = null)
 * @method static Once once(mixed $value, ?string $key = null, \DateInterval|int|null $ttl = null)
 * @method static Watch watch(mixed $value, \Illuminate\Database\Eloquent\Model|string ...$sources)
 * @method static Mode mode(?Request $request = null)
 * @method static void encryptHistory(bool $encrypt = true, ?Request $request = null)
 * @method static void clearHistory(?Request $request = null)
 *
 * @see BridgeManager
 */
class Bridge extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BridgeManager::class;
    }
}
