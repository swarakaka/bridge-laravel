<?php

declare(strict_types=1);

namespace Bridge\Ssr;

use Illuminate\Http\Client\Factory as Http;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * POSTs the page object to the SSR server (`@swarakaka/bridge-vue/server`)
 * and returns its {head, body}. Any failure falls back to client rendering.
 */
final class HttpSsrGateway implements SsrGateway
{
    public function __construct(
        private readonly Http $http,
        private readonly LoggerInterface $logger,
        private readonly string $url,
        private readonly float $timeoutSeconds = 2.0,
    ) {}

    public function render(array $page): ?SsrResult
    {
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
        } catch (Throwable $e) {
            $this->logger->warning('Bridge SSR request failed; rendering on the client.', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
