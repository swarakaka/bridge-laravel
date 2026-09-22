<?php

declare(strict_types=1);

namespace Bridge\Negotiation;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class NotAcceptableException extends HttpException
{
    /**
     * @param  list<string>  $acceptable
     */
    public function __construct(public readonly array $acceptable)
    {
        parent::__construct(406, 'Not Acceptable');
    }

    /**
     * @return array<string, mixed>
     */
    public function toBody(): array
    {
        return ['message' => 'Not Acceptable', 'acceptable' => $this->acceptable];
    }
}
