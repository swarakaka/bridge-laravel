<?php

declare(strict_types=1);

namespace Bridge\Support;

/**
 * Header names defined in packages/protocol/spec/headers.md.
 */
final class Headers
{
    public const BUILD = 'X-Bridge-Build';

    public const ONLY = 'X-Bridge-Only';

    public const EXCEPT = 'X-Bridge-Except';

    public const COMPONENT = 'X-Bridge-Component';

    public const LOCATION = 'X-Bridge-Location';

    public const LAST_EVENT_ID = 'Last-Event-ID';

    /** Query fallback for Last-Event-ID: a new native EventSource cannot send the header. */
    public const LAST_EVENT_ID_QUERY = 'lastEventId';

    public const VARY = 'Accept, X-Bridge-Only, X-Bridge-Except, X-Bridge-Component';

    /** Element id of the embedded page object in an HTML shell. */
    public const EMBEDDED_PAGE_ID = 'bridge-page';
}
