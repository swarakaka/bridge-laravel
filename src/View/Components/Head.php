<?php

declare(strict_types=1);

namespace Bridge\View\Components;

use Bridge\Support\Shell;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * <x-bridge::head /> — protocol and build meta tags plus SSR head fragments.
 * Same output as the @bridgeHead directive.
 */
final class Head extends Component
{
    /**
     * @param  list<string>|null  $ssrHead
     */
    public function __construct(
        public ?int $protocol = null,
        public ?string $build = null,
        public ?array $ssrHead = null,
    ) {}

    public function render(): View
    {
        $shell = Shell::current(app('request'));

        return $this->view('bridge::components.head', [
            'html' => Shell::head(
                $this->protocol ?? (int) ($shell['protocol'] ?? 1),
                $this->build ?? ($shell['build'] ?? null),
                $this->ssrHead ?? ($shell['ssrHead'] ?? []),
            ),
        ]);
    }
}
