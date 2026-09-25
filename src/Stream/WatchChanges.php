<?php

declare(strict_types=1);

namespace Bridge\Stream;

use Bridge\Stream\Bus\Envelope;
use Bridge\Stream\Contracts\EventBus;
use Bridge\Support\Headers;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Buffers changes to watched data and publishes them as `invalidate` messages
 * with `tags` (PLAN §20.6, spec/stream.md §3.1). A change is recorded after
 * its database transaction commits (dropped on rollback) and published when
 * the request, job or command ends: one message per channel and client,
 * however many records changed. Beyond `max_tags` records of one model a
 * channel gets `<tag>.*` instead of the record tags.
 *
 * A singleton: it reads the request, bus and database from the current
 * container at call time, so Octane's per-request sandboxes are respected.
 */
final class WatchChanges
{
    /** `X-Bridge-Client`: a 128-bit base64url token and the request number. */
    private const CLIENT_PATTERN = '/^([A-Za-z0-9_-]{22})\.([0-9]{1,15})$/';

    /**
     * Pending changes: channel → client echo ('' for none) → record keys per
     * `<tag>` and other tags.
     *
     * @var array<string, array<string, array{bases: array<string, array<string, true>>, tags: array<string, true>}>>
     */
    private array $pending = [];

    /**
     * @param  list<string>  $defaultChannels  channels of models without streamOn()
     */
    public function __construct(
        private readonly WatchTags $tags,
        private readonly array $defaultChannels,
        private readonly int $maxTags = 50,
    ) {}

    /** A model with StreamsChanges was created, updated, deleted or restored. */
    public function modelChanged(Model $model): void
    {
        $channels = method_exists($model, 'streamOn') ? $model->streamOn() : $this->defaultChannels;

        $this->record(is_array($channels) ? array_values(array_map('strval', $channels)) : [], [$model], $model->getConnection());
    }

    /**
     * Record changes to `$sources` for `$channels`, after the current
     * transaction of `$connection` (default connection) commits.
     *
     * @param  list<string>  $channels
     * @param  list<Model|string>  $sources
     */
    public function record(array $channels, array $sources, ?ConnectionInterface $connection = null): void
    {
        if ($channels === [] || $sources === []) {
            return;
        }

        $client = $this->clientEcho();
        $bases = [];
        $tags = [];

        foreach ($sources as $source) {
            if ($source instanceof Model) {
                $bases[$this->tags->base($source)][(string) $source->getKey()] = true;
                // Validates the key.
                $this->tags->record($source);

                continue;
            }

            foreach ($this->tags->forChange($source) as $tag) {
                $tags[$tag] = true;
            }
        }

        $add = function () use ($channels, $client, $bases, $tags): void {
            foreach ($channels as $channel) {
                $entry = $this->pending[$channel][$client] ?? ['bases' => [], 'tags' => []];

                foreach ($bases as $base => $keys) {
                    $entry['bases'][$base] = ($entry['bases'][$base] ?? []) + $keys;
                }

                $entry['tags'] += $tags;
                $this->pending[$channel][$client] = $entry;
            }
        };

        $connection ??= $this->defaultConnection();

        if ($connection !== null && method_exists($connection, 'afterCommit')) {
            try {
                $connection->afterCommit($add);

                return;
            } catch (\RuntimeException) {
                // No transactions manager: nothing can be pending, record now.
            }
        }

        $add();
    }

    /** Publish everything recorded so far. Called at the end of each request, job and command. */
    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        if ($pending === []) {
            return;
        }

        $bus = $this->container()->make(EventBus::class);

        foreach ($pending as $channel => $byClient) {
            foreach ($byClient as $client => $entry) {
                $message = StreamMessage::invalidate([], $this->tagList($entry), $client === '' ? null : $client);
                $bus->publish([(string) $channel], Envelope::make($message->event, $message->data));
            }
        }
    }

    public function hasPending(): bool
    {
        return $this->pending !== [];
    }

    /**
     * @param  array{bases: array<string, array<string, true>>, tags: array<string, true>}  $entry
     * @return list<string>
     */
    private function tagList(array $entry): array
    {
        $list = [];

        foreach ($entry['bases'] as $base => $keys) {
            $list[] = $base;

            if (count($keys) > $this->maxTags) {
                $list[] = $base.'.*';

                continue;
            }

            foreach (array_keys($keys) as $key) {
                $list[] = $base.'.'.$key;
            }
        }

        foreach (array_keys($entry['tags']) as $tag) {
            $list[] = $tag;
        }

        return array_values(array_unique($list));
    }

    /**
     * `client` of the messages (spec/stream.md §3.2): the request number
     * with a hash of the token, never the token itself. '' outside a request
     * that sent a well-formed `X-Bridge-Client`.
     */
    private function clientEcho(): string
    {
        if (! $this->container()->bound('request')) {
            return '';
        }

        /** @var Request $request */
        $request = $this->container()->make('request');
        $header = (string) $request->headers->get(Headers::CLIENT, '');

        if (preg_match(self::CLIENT_PATTERN, $header, $matches) !== 1) {
            return '';
        }

        $hash = substr(hash('sha256', $matches[1], true), 0, 16);

        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=').'.'.$matches[2];
    }

    private function container(): Container
    {
        return Container::getInstance();
    }

    private function defaultConnection(): ?ConnectionInterface
    {
        if (! $this->container()->bound('db')) {
            return null;
        }

        try {
            return $this->container()->make('db')->connection();
        } catch (\Throwable) {
            return null;
        }
    }
}
