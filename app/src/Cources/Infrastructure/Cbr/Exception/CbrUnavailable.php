<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Cbr\Exception;

use Throwable;

final class CbrUnavailable extends CbrGatewayException
{
    public function __construct(string $message, public readonly ?int $httpStatus = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
