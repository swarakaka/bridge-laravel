<?php

declare(strict_types=1);

namespace Bridge\Ssr;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * POSTs the page object to the SSR server (`@swarakaka/bridge-vue/server`)
 * and returns its {head, body}. Any failure falls back to client rendering.
 *
 * When the server cannot be reached (refused, timed out), SSR is skipped for
 * `cooldownSeconds`, so a hung server does not add the timeout to every HTML
 * response. An error status means the server is up and does not trip it.
 */
final class HttpSsrGateway implements SsrGateway
{
    public function __construct(
        private readonly Http $http,
        private readonly LoggerInterface $logger,
        private readonly string $url,
        private readonly float $timeoutSeconds = 2.0,
        private readonly ?Cache $cache = null,
        private readonly int $cooldownSeconds = 10,
    ) {}

    public const DOWN_KEY = 'bridge:ssr:down';

    public function render(array $page): ?SsrResult
    {
        if ($this->cache !== null && $this->cache->has(self::DOWN_KEY)) {
            return null;
        }

        try {
            $response = $this->http
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->post(rtrim($this->url, '/').'/render', $page);

            if (! $response->successful()) {
                $this->logger->warning('Bridge SSR returned a non-2xx status; rendering on the client.', ['status' => $response->status()]);

                return null;
            }

            $data = $response->json();

            if (! is_array($data) || ! is_string($data['body'] ?? null)) {
                $this->logger->warning('Bridge SSR returned an invalid payload; rendering on the client.');

                return null;
            }

            $head = array_values(array_filter((array) ($data['head'] ?? []), 'is_string'));

            return new SsrResult($head, $data['body']);
        } catch (ConnectionException $e) {
            if ($this->cache !== null && $this->cooldownSeconds > 0) {
                $this->cache->put(self::DOWN_KEY, true, $this->cooldownSeconds);
            }
            $this->logger->warning("Bridge SSR is unreachable; rendering on the client for {$this->cooldownSeconds}s.", ['error' => $e->getMessage()]);

            return null;
        } catch (Throwable $e) {
            $this->logger->warning('Bridge SSR request failed; rendering on the client.', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
