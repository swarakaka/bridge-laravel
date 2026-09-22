<?php

declare(strict_types=1);

namespace Bridge\Support;

use Bridge\Representation\HtmlRepresenter;

/**
 * Renders the pieces of the HTML shell used by the @bridge / @bridgeHead directives.
 */
final class Shell
{
    /**
     * @param  array<string, mixed>|null  $page
     */
    public static function root(string $id, ?array $page): string
    {
        $html = '';

        if ($page !== null) {
            $html .= '<script type="application/json" id="'.Headers::EMBEDDED_PAGE_ID.'">'
                .HtmlRepresenter::encodeEmbedded($page)
                .'</script>'."\n";
        }

        return $html.'<div id="'.htmlspecialchars($id, ENT_QUOTES).'" data-bridge></div>';
    }

    public static function head(int $protocol, ?string $build): string
    {
        $html = '<meta name="bridge-protocol" content="'.$protocol.'">';

        if ($build !== null) {
            $html .= "\n".'<meta name="bridge-build" content="'.htmlspecialchars($build, ENT_QUOTES).'">';
        }

        return $html;
    }
}
