<?php

declare(strict_types=1);

namespace App\Cources\Application\Exception;

use App\Support\Retry\Application\RetryableFailure;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class RateSetLoadBusy extends \RuntimeException implements RetryableFailure
{
    public function __construct(string $message = 'Набор курсов уже загружается другим исполнителем.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function retryAt(): ?DatePoint
    {
        return null;
    }
}
