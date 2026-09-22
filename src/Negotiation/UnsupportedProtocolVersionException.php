<?php

declare(strict_types=1);

namespace Bridge\Negotiation;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class UnsupportedProtocolVersionException extends HttpException
{
    /**
     * @param  list<int>  $supported
     */
    public function __construct(public readonly array $supported)
    {
        parent::__construct(406, 'Unsupported Bridge protocol version');
    }

    /**
     * @return array<string, mixed>
     */
    public function toBody(): array
    {
        return ['message' => 'Unsupported Bridge protocol version', 'supported' => $this->supported];
    }
}
