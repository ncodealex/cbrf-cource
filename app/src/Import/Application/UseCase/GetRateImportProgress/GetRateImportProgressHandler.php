<?php

declare(strict_types=1);

namespace App\Import\Application\UseCase\GetRateImportProgress;

use App\Import\Application\Contract\ImportJobQueryRepositoryInterface;
use App\Import\Application\DTO\RateImportProgressSnapshot;
use App\Import\Domain\ValueObject\RatesImportPeriod;

final readonly class GetRateImportProgressHandler
{
    public function __construct(
        private ImportJobQueryRepositoryInterface $repository,
    ) {
    }

    public function __invoke(
        RatesImportPeriod $period,
        int $elapsed = 0,
        int $initialConfirmed = 0,
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
