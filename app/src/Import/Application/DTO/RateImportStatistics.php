<?php

declare(strict_types=1);

namespace App\Import\Application\DTO;

use App\Import\Domain\Enum\RateImportStatus;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class RateImportStatistics
{
    public function __construct(
        public int $waiting,
        public int $processing,
        public int $deferred,
        public int $done,
        public int $error,
    ) {
    }

    public function get(RateImportStatus $status): int
    {
        return match ($status) {
            RateImportStatus::Waiting => $this->waiting,
            RateImportStatus::Processing => $this->processing,
            RateImportStatus::Deferred => $this->deferred,
            RateImportStatus::Done => $this->done,
            RateImportStatus::Error => $this->error,
        };
    }
}
