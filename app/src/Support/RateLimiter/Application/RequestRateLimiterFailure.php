<?php

declare(strict_types=1);

namespace App\Support\RateLimiter\Application;

use App\Support\Retry\Application\AdmissionFailure;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class RequestRateLimiterFailure extends \RuntimeException implements AdmissionFailure
{
    public function __construct(
        string $message = 'Не удалось проверить глобальный лимит запросов.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message,
            0,
            $previous,
        );
    }

    public function retryAt(): ?DatePoint
    {
        return null;
    }
}
