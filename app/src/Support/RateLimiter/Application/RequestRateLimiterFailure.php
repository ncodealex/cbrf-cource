<?php

declare(strict_types=1);

namespace App\Support\RateLimiter\Application;

use App\Support\Retry\Application\RetryableFailure;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class RequestRateLimiterFailure extends \RuntimeException implements RetryableFailure
{
    public function __construct(string $message = 'Не удалось проверить глобальный лимит запросов.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function retryAt(): ?DatePoint
    {
        return null;
    }
}
