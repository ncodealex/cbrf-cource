<?php

declare(strict_types=1);

namespace App\Courses\Infrastructure\Cbr\Exception;

use App\Courses\Application\Exception\GatewayNotSupportRateSetDateInterface;

final class CbrNotSupportRateSetDate extends CbrGatewayException implements GatewayNotSupportRateSetDateInterface
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message,
            0,
            $previous,
        );
    }
}
