<?php

declare(strict_types=1);

namespace Bridge\Http\Responses;

use Bridge\Negotiation\Negotiation;
use Bridge\Props\Serializer;
use Bridge\Representation\RepresenterRegistry;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Returned by Bridge::redirect(). Represented as 302 (html), 303 (page) or a
 * result document with Location (json). See spec/page.md §6 and spec/json.md §3.
 */
final class RedirectBuilder implements Responsable
{
    private ?string $url = null;

    private bool $back = false;

    private bool $external = false;

    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<string, mixed> */
    private array $flash = [];

    private ?int $jsonStatus = null;

    public function __construct(
        private readonly UrlGenerator $urls,
        private readonly Serializer $serializer,
        private readonly RepresenterRegistry $representers,
        private readonly Repository $config,
    ) {}

    public function to(string $url): self
    {
        $this->url = $url;
        $this->back = false;

        return $this;
    }

    /**
     * @param  array<string, mixed>|mixed  $parameters
     */
    public function route(string $name, mixed $parameters = [], bool $absolute = true): self
    {
        return $this->to($this->urls->route($name, $parameters, $absolute));
    }

    public function back(string $fallback = '/'): self
    {
        $this->back = true;
        $this->url = $fallback;

        return $this;
    }

    public function external(bool $external = true): self
    {
        $this->external = $external;

        return $this;
    }

    public function with(string $key, mixed $value): self
    {
        $this->data[$key] = $value;

        return $this;
    }

    /**
     * Flash a message for the next page. Keys default to the configured
     * `bridge.flash.keys` order (message, level).
     */
    public function flash(string $message, string $level = 'success'): self
    {
        [$messageKey, $levelKey] = $this->flashKeys();
        $this->flash[$messageKey] = $message;
        $this->flash[$levelKey] = $level;

        return $this;
    }

    public function status(int $status): self
    {
        $this->jsonStatus = $status;

        return $this;
    }

    public function created(): self
    {
        return $this->status(201);
    }

    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        $negotiation = Negotiation::for($request);
        $url = $this->resolveUrl($request);

        $data = [];

        foreach ($this->data as $key => $value) {
            $data[$key] = $this->serializer->serialize($value, $request);
        }

        // Flash to the session (when one exists) so the next HTML/page load
        // sees it through the `flash` shared prop; JSON mode echoes it in meta.
        if ($this->flash !== [] && $request->hasSession()) {
            foreach ($this->flash as $key => $value) {
                $request->session()->flash($key, $value);
            }
        }

        $redirect = new Redirect(
            url: $url,
            data: $data,
            flash: $this->flash === [] ? null : $this->flash,
            jsonStatus: $this->jsonStatus,
            external: $this->external,
        );

        return $this->representers->for($negotiation->mode)->representRedirect($redirect, $negotiation, $request);
    }

    private function resolveUrl(Request $request): string
    {
        if ($this->back) {
            return $this->urls->previous($this->url ?? '/');
        }

        return $this->url ?? '/';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function flashKeys(): array
    {
        $keys = $this->config->get('bridge.flash.keys', ['message', 'level']);
        $keys = is_array($keys) ? array_values($keys) : ['message', 'level'];

        return [(string) ($keys[0] ?? 'message'), (string) ($keys[1] ?? 'level')];
    }
}
