<?php

declare(strict_types=1);

namespace Bridge\View\Components;

use Bridge\Representation\HtmlRepresenter;
use Bridge\Support\Headers;
use Bridge\Support\Shell;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\View\Component;

/**
 * <x-bridge::app /> — the SPA mount point: the embedded page data block and
 * the root element. Same output as the @bridge directive, with attribute
 * passthrough (class, data-*, id) and explicit overrides for the page.
 */
final class App extends Component
{
    /**
     * @param  array<string, mixed>|false|null  $page  null: the current Bridge response's page; false: no embedded page
     * @param  string|false|null  $ssrBody  null: the current response's server-rendered body; false: none
     */
    public function __construct(
        public array|false|null $page = null,
        public string|false|null $ssrBody = null,
        public ?string $id = null,
    ) {}

    public function render(): View
    {
        $shell = Shell::current($this->request());
        $page = $this->page === false ? null : ($this->page ?? $shell['page'] ?? null);
        $ssrBody = $this->ssrBody === false ? null : ($this->ssrBody ?? $shell['ssrBody'] ?? null);

        return $this->view('bridge::components.app', [
            'rootId' => $this->id ?? (string) config('bridge.shell.root_id', 'app'),
            'embedded' => $page === null ? null : HtmlRepresenter::encodeEmbedded($page),
            'embeddedId' => Headers::EMBEDDED_PAGE_ID,
            'body' => $ssrBody,
        ]);
    }

    private function request(): Request
    {
        return app('request');
    }
}
