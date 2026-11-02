<?php

declare(strict_types=1);

namespace App\Cources\Application\Import;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class RateImportSettings
{
    public function __construct(
        #[Autowire('%env(int:IMPORT_DISPATCH_BATCH_SIZE)%')]
        public int $batchSize,
        #[Autowire('%env(int:IMPORT_DISPATCH_INTERVAL_SECONDS)%')]
        public int $interval,
        #[Autowire('%env(int:IMPORT_REDISPATCH_AFTER_SECONDS)%')]
        public int $redispatchAfter,
        #[Autowire('%env(int:IMPORT_MAX_UNCLAIMED)%')]
        public int $maxUnclaimed,
        #[Autowire('%env(int:IMPORT_LEASE_SECONDS)%')]
        public int $leaseSeconds,
        #[Autowire('%env(int:IMPORT_PROGRESS_INTERVAL_SECONDS)%')]
        public int $progressInterval,
        #[Autowire('%env(int:IMPORT_PROGRESS_TIMEOUT_SECONDS)%')]
        public int $progressTimeout,
    ) {
        if (min($batchSize, $interval, $redispatchAfter, $maxUnclaimed, $leaseSeconds, $progressInterval) < 1 || $progressTimeout < 0) {
            throw new \InvalidArgumentException('Некорректные параметры импорта.');
        }
    }
}
