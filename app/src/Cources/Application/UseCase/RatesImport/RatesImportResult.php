<?php

declare(strict_types=1);

namespace App\Cources\Application\UseCase\RatesImport;

use App\Cources\Application\Contract\RateImportJobRepositoryInterface;
use App\Cources\Application\Import\RatesImportPeriod;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** @phpstan-import-type ImportStatistics from RateImportJobRepositoryInterface */
#[Exclude]
final readonly class RatesImportResult
{
    /** @param ImportStatistics $states */
    public function __construct(
        public RatesImportPeriod $period,
        public int $total,
        public int $confirmed,
        public int $registered,
        public int $created,
        public array $states,
        public int $requeuedDone,
    ) {
    }
}
