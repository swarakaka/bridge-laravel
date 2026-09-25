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

    /** Once keys the client holds (spec/page.md §11). Page mode only. */
    public const ONCE = 'X-Bridge-Once';

    /** `<token>.<seq>` of the sending client, echoed hashed on watch invalidations (spec/stream.md §3.2). */
    public const CLIENT = 'X-Bridge-Client';

    public const LAST_EVENT_ID = 'Last-Event-ID';

    /** Query fallback for Last-Event-ID: a new native EventSource cannot send the header. */
    public const LAST_EVENT_ID_QUERY = 'lastEventId';

    /** `Vary` on JSON responses. */
    public const VARY = 'Accept, X-Bridge-Only, X-Bridge-Except, X-Bridge-Component';

    /** `Vary` on page responses: the JSON value plus X-Bridge-Once. */
    public const PAGE_VARY = 'Accept, X-Bridge-Only, X-Bridge-Except, X-Bridge-Component, X-Bridge-Once';

    /** Element id of the embedded page object in an HTML shell. */
    public const EMBEDDED_PAGE_ID = 'bridge-page';
}
