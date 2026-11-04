<?php

declare(strict_types=1);

namespace App\Import\Application\Configuration;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class RateImportSettings
{
    public function __construct(
        #[Autowire('%env(int:default:app.import.default_dispatch_batch_size:IMPORT_DISPATCH_BATCH_SIZE)%')]
        public int $batchSize,
        #[Autowire('%env(int:default:app.import.default_dispatch_interval_seconds:IMPORT_DISPATCH_INTERVAL_SECONDS)%')]
        public int $interval,
        #[Autowire('%env(int:default:app.import.default_redispatch_after_seconds:IMPORT_REDISPATCH_AFTER_SECONDS)%')]
        public int $redispatchAfter,
        #[Autowire('%env(int:default:app.import.default_max_unclaimed:IMPORT_MAX_UNCLAIMED)%')]
        public int $maxUnclaimed,
        #[Autowire('%env(int:default:app.import.default_lease_seconds:IMPORT_LEASE_SECONDS)%')]
        public int $leaseSeconds,
        #[Autowire('%env(int:default:app.import.default_progress_interval_seconds:IMPORT_PROGRESS_INTERVAL_SECONDS)%')]
        public int $progressInterval,
        #[Autowire('%env(int:default:app.import.default_progress_timeout_seconds:IMPORT_PROGRESS_TIMEOUT_SECONDS)%')]
        public int $progressTimeout,
    ) {
        if (min($batchSize, $interval, $redispatchAfter, $maxUnclaimed, $leaseSeconds, $progressInterval) < 1 || $progressTimeout < 0) {
            throw new \InvalidArgumentException('Некорректные параметры импорта.');
        }
    }
}
