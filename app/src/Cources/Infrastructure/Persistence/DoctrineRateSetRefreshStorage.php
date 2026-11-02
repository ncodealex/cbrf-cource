<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Persistence;

use App\Cources\Application\Contract\RateImportJobRepositoryInterface;
use App\Cources\Application\Contract\RateSetRefreshStorageInterface;
use App\Cources\Application\Contract\RateSetRepositoryInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(RateSetRefreshStorageInterface::class)]
final readonly class DoctrineRateSetRefreshStorage implements RateSetRefreshStorageInterface
{
    public function __construct(
        private Connection $connection,
        private RateImportJobRepositoryInterface $importJobs,
        private RateSetRepositoryInterface $repository,
    ) {
    }

    public function invalidate(DatePoint $requestDate, DatePoint ...$relatedDates): void
    {
        $this->connection->transactional(function () use ($requestDate, $relatedDates): void {
            $this->importJobs->resetForRefresh(...([] === $relatedDates ? [$requestDate] : $relatedDates));
            $this->repository->delete($requestDate);
        });
    }
}
