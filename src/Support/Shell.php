<?php

declare(strict_types=1);

namespace Bridge\Support;

use Bridge\Representation\HtmlRepresenter;
use Illuminate\Http\Request;

/**
 * Renders the pieces of the HTML shell used by the @bridge / @bridgeHead directives.
 */
final class Shell
{
    /** Request attribute holding the current response's shell data for the Blade components. */
    public const ATTRIBUTE = 'bridge.shell';

    /**
     * @param  array<string, mixed>|null  $page
     * @param  list<string>  $ssrHead
     */
    public static function remember(Request $request, ?array $page, int $protocol, ?string $build, array $ssrHead, ?string $ssrBody): void
    {
        $request->attributes->set(self::ATTRIBUTE, [
            'page' => $page,
            'protocol' => $protocol,
            'build' => $build,
            'ssrHead' => $ssrHead,
            'ssrBody' => $ssrBody,
        ]);
    }

    /**
     * @return array{page?: array<string, mixed>|null, protocol?: int, build?: string|null, ssrHead?: list<string>, ssrBody?: string|null}
     */
    public static function current(Request $request): array
    {
        $shell = $request->attributes->get(self::ATTRIBUTE);

        return is_array($shell) ? $shell : [];
    }

    /**
     * @param  array<string, mixed>|null  $page
     */
    public static function root(string $id, ?array $page, ?string $ssrBody = null): string
    {
        $html = '';

        if ($page !== null) {
            $html .= '<script type="application/json" id="'.Headers::EMBEDDED_PAGE_ID.'">'
                .HtmlRepresenter::encodeEmbedded($page)
                .'</script>'."\n";
        }

        $attributes = $ssrBody !== null ? ' data-server-rendered="true"' : '';

        return $html.'<div id="'.htmlspecialchars($id, ENT_QUOTES).'" data-bridge'.$attributes.'>'.($ssrBody ?? '').'</div>';
    }

    /**
     * @param  list<string>  $ssrHead
     */
    public static function head(int $protocol, ?string $build, array $ssrHead = []): string
    {
        $html = '<meta name="bridge-protocol" content="'.$protocol.'">';

        if ($build !== null) {
            $html .= "\n".'<meta name="bridge-build" content="'.htmlspecialchars($build, ENT_QUOTES).'">';
        }

        foreach ($ssrHead as $fragment) {
            $html .= "\n".$fragment;
        }

        return $html;
    }
}
