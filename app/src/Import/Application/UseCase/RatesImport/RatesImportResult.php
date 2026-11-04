<?php

declare(strict_types=1);

namespace App\Import\Application\UseCase\RatesImport;

use App\Import\Application\DTO\RateImportStatistics;
use App\Import\Domain\ValueObject\RatesImportPeriod;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class RatesImportResult
{
    public function __construct(
        public RatesImportPeriod $period,
        public int $total,
        public int $confirmed,
        public int $registered,
        public int $created,
        public RateImportStatistics $statistics,
        public int $requeuedDone,
    ) {
    }
}
