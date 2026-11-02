<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Cbr\Exception;

use App\Support\Retry\Application\AdmissionFailure;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class CbrRequestRateLimitExceeded extends CbrGatewayException implements AdmissionFailure
{
    public function __construct(private readonly DatePoint $nextAttempt)
    {
        parent::__construct('Достигнут глобальный лимит обращений к Банку России.');
    }

    public function retryAt(): DatePoint
    {
        return $this->nextAttempt;
    }
}
