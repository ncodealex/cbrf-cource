<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Cbr\Exception;

use App\Support\Retry\Application\RetryableFailure;
use Symfony\Component\Clock\DatePoint;

final class CbrUnavailable extends CbrGatewayException implements RetryableFailure
{
    public function __construct(string $message, public readonly ?int $httpStatus = null, ?\Throwable $previous = null, private readonly ?DatePoint $nextAttempt = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function retryAt(): ?DatePoint
    {
        return $this->nextAttempt;
    }
}
