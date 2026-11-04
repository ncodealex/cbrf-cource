<?php

declare(strict_types=1);

namespace App\Cources\Application\UseCase\RetryRateImportErrors;

use App\Cources\Application\Contract\RateImportJobRepositoryInterface;

final readonly class RetryRateImportErrorsHandler
{
    public function __construct(
        private RateImportJobRepositoryInterface $repository,
    ) {
    }

    public function __invoke(?string $date): int
    {
        return $this->repository->retryErrors($date);
    }
}
