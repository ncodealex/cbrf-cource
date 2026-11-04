<?php

declare(strict_types=1);

namespace App\Import\Application\Exception;

use App\Support\Retry\Application\RetryableFailure;
use Symfony\Component\Clock\DatePoint;

final class ExpiredImportLease extends \RuntimeException implements RetryableFailure
{
    /**
     * {@inheritdoc}
     */
    public function retryAt(): ?DatePoint
    {
        return null;
    }
}
