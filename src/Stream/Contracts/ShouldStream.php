<?php

declare(strict_types=1);

namespace Bridge\Stream\Contracts;

use Bridge\Stream\StreamMessage;

/**
 * Mirrors ShouldBroadcast: dispatching an implementing event publishes it to the bus.
 */
interface ShouldStream
{
    /**
     * @return list<string>
     */
    public function streamOn(): array;

    /**
     * One message, or several published in order.
     *
     * @return StreamMessage|list<StreamMessage>
     */
    public function toStream(): StreamMessage|array;
}
