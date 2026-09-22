<?php

declare(strict_types=1);

namespace Bridge\Stream;

use Closure;
use Illuminate\Contracts\Container\Container;

/**
 * Pattern-based channel authorization, same syntax as Broadcast::channel:
 * Bridge::channel('tenant.{tenantId}', fn ($user, $tenantId) => ...).
 */
final class ChannelAuthorizer
{
    /** @var array<string, Closure> */
    private array $patterns = [];

    public function __construct(private readonly Container $container) {}

    public function register(string $pattern, Closure $callback): void
    {
        $this->patterns[$pattern] = $callback;
    }

    /**
     * True when a registered pattern matches and its callback returns truthy.
     */
    public function authorize(mixed $user, string $channel): bool
    {
        foreach ($this->patterns as $pattern => $callback) {
            $parameters = $this->match($pattern, $channel);

            if ($parameters === null) {
                continue;
            }

            $result = $this->container->call($callback, ['user' => $user, ...$parameters]);

            return (bool) $result;
        }

        return false;
    }

    /**
     * @return array<string, string>|null
     */
    private function match(string $pattern, string $channel): ?array
    {
        $parts = preg_split('/(\{[a-zA-Z_][a-zA-Z0-9_]*\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$pattern];
        $regex = '/^';

        foreach ($parts as $part) {
            $regex .= preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $part, $m)
                ? '(?P<'.$m[1].'>[^.]+)'
                : preg_quote($part, '/');
        }

        $regex .= '$/';

        if (! preg_match($regex, $channel, $m)) {
            return null;
        }

        $parameters = [];

        foreach ($m as $key => $value) {
            if (is_string($key)) {
                $parameters[$key] = $value;
            }
        }

        return $parameters;
    }

    /**
     * @return list<string>
     */
    public function patterns(): array
    {
        return array_keys($this->patterns);
    }
}
