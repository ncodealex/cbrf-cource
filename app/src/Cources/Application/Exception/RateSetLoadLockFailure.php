<?php

declare(strict_types=1);

namespace App\Cources\Application\Exception;

use App\Support\Retry\Application\RetryableFailure;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class RateSetLoadLockFailure extends \RuntimeException implements RetryableFailure
{
    public function __construct(string $message = 'Не удалось обеспечить блокировку загрузки курсов.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function retryAt(): ?DatePoint
    {
        return null;
    }
}
