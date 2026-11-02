<?php

declare(strict_types=1);

namespace App\Cources\Application\UseCase\GetRateImportProgress;

use App\Cources\Application\Contract\RateImportJobRepositoryInterface;
use App\Cources\Application\Import\RatesImportPeriod;

final readonly class GetRateImportProgressHandler
{
    public function __construct(private RateImportJobRepositoryInterface $repository)
    {
    }

    public function __invoke(RatesImportPeriod $period, int $elapsed = 0, int $initialConfirmed = 0): \App\Cources\Application\Import\RateImportProgressSnapshot
    {
        return new \App\Cources\Application\Import\RateImportProgressSnapshot($period->count(), ...$this->repository->progress($period), elapsed: $elapsed, initialConfirmed: $initialConfirmed);
    }
}
