<?php

declare(strict_types=1);

namespace Bridge\Facades;

use Bridge\Http\Responses\PageResponse;
use Bridge\Http\Responses\RedirectBuilder;
use Bridge\Negotiation\Mode;
use Bridge\Props\Always;
use Bridge\Props\Deferred;
use Bridge\Props\Lazy;
use Closure;
use Illuminate\Support\Facades\Facade;

/**
 * @method static PageResponse render(string $component, array<string, mixed> $props = [])
 * @method static RedirectBuilder redirect()
 * @method static \Bridge\Stream\StreamResponse stream(?callable $producer = null)
 * @method static \Bridge\Stream\Publisher to(string|array<int, string> $channels)
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
 * @method static Mode mode(?\Illuminate\Http\Request $request = null)
 *
 * @see \Bridge\Bridge
 */
final class Bridge extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Bridge\Bridge::class;
    }
}
