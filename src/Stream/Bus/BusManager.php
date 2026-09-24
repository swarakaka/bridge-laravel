<?php

declare(strict_types=1);

namespace Bridge\Stream\Bus;

use Bridge\Stream\Contracts\EventBus;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Manager;

/**
 * @method EventBus driver(?string $driver = null)
 */
final class BusManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('bridge.stream.driver', 'database');
    }

    public function createSyncDriver(): EventBus
    {
        return new SyncBus;
    }

    public function createNullDriver(): EventBus
    {
        return new NullBus;
    }

    public function createDatabaseDriver(): EventBus
    {
        $options = (array) $this->config->get('bridge.stream.drivers.database', []);
        $connection = $options['connection'] ?? null;

        return new DatabaseBus(
            $this->container->make(DatabaseManager::class)->connection(is_string($connection) ? $connection : null),
            (string) ($options['table'] ?? 'bridge_stream_events'),
            // At least 10 ms between polls, whatever the configuration says.
            max(10, (int) ($options['poll_ms'] ?? 1000)),
            (string) $this->config->get('bridge.stream.prefix', 'bridge'),
            (int) ($options['lookback'] ?? 200),
        );
    }

    public function createRedisDriver(): EventBus
    {
        $options = (array) $this->config->get('bridge.stream.drivers.redis', []);
        $connection = $options['connection'] ?? null;

        return new RedisStreamsBus(
            $this->container->make(RedisFactory::class),
            is_string($connection) ? $connection : null,
            max(1, (int) ($options['maxlen'] ?? 1000)),
            (string) $this->config->get('bridge.stream.prefix', 'bridge'),
            isset($options['retain_minutes']) ? max(1, (int) $options['retain_minutes']) * 60 : null,
            $this->maxBlockMs(is_string($connection) ? $connection : 'default'),
        );
    }

    /**
     * Half a second below the connection's read timeout: `read_timeout`
     * (phpredis) or `read_write_timeout` (predis), in seconds, else PHP's
     * `default_socket_timeout`, which both clients fall back to. A value of 0
     * or -1 means no timeout.
     */
    private function maxBlockMs(string $connection): ?int
    {
        $timeout = $this->config->get("database.redis.{$connection}.read_timeout")
            ?? $this->config->get("database.redis.{$connection}.read_write_timeout")
            ?? ini_get('default_socket_timeout');

        if (! is_numeric($timeout) || (float) $timeout <= 0) {
            return null;
        }

        return max(100, (int) ((float) $timeout * 1000) - 500);
    }
}
