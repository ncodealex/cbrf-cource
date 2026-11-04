<?php

declare(strict_types=1);

namespace App\Cources\Application\UseCase\GetRateImportProgress;

use App\Cources\Application\Contract\RateImportJobRepositoryInterface;
use App\Cources\Application\Import\RateImportProgressSnapshot;
use App\Cources\Application\Import\RatesImportPeriod;

final readonly class GetRateImportProgressHandler
{
    public function __construct(
        private RateImportJobRepositoryInterface $repository,
    ) {
    }

    public function __invoke(
        RatesImportPeriod $period,
        int $elapsed = 0,
        int $initialConfirmed = 0
    ): RateImportProgressSnapshot {
        $progress = $this->repository->progress($period);

        return new RateImportProgressSnapshot(
            total: $period->count(),
            confirmed: $progress->confirmed,
            waiting: $progress->waiting,
            processing: $progress->processing,
            deferred: $progress->deferred,
            error: $progress->error,
            inconsistentDone: $progress->inconsistentDone,
            missing: $progress->missing,
            elapsed: $elapsed,
            initialConfirmed: $initialConfirmed,
        );
    }
}
