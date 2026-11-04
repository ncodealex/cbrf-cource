<?php

declare(strict_types=1);

namespace App\Import\Application\UseCase\RetryRateImportErrors;

use App\Import\Application\Contract\ImportJobRepositoryInterface;

final readonly class RetryRateImportErrorsHandler
{
    public function __construct(
        private ImportJobRepositoryInterface $repository,
    ) {
    }

    public function __invoke(?string $date): int
    {
        // 1. Сбросить выбранные терминальные ошибки для повторной диспетчеризации.
        return $this->repository->retryErrors($date);
    }
}
