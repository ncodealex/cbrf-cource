<?php

declare(strict_types=1);

namespace App\Cources\Application\Exception;

final class CbrRateLimited extends CbrGatewayException
{
    public function __construct(string $message, public readonly ?int $httpStatus = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
