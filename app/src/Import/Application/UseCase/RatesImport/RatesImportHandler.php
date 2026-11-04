<?php

declare(strict_types=1);

namespace App\Import\Application\UseCase\RatesImport;

use App\Import\Application\Contract\ImportJobQueryRepositoryInterface;
use App\Import\Application\Contract\ImportJobRepositoryInterface;
use App\Import\Domain\ValueObject\RatesImportPeriod;
use App\Shared\Domain\DateFormatInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\DatePoint;

final readonly class RatesImportHandler
{
    public function __construct(
        private ImportJobRepositoryInterface $repository,
        private ImportJobQueryRepositoryInterface $queries,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(RatesImportPeriod $period): RatesImportResult
    {
        $requeuedDone = $this->repository->requeueUnconfirmedDone($period);
        $confirmed = [];
        foreach ($this->queries->confirmedDates($period) as $date) {
            $confirmed[$date->format(DateFormatInterface::ISO)] = true;
        }
        $missing = [];
        foreach ($period->dates() as $date) {
            if (!isset($confirmed[$date->format(DateFormatInterface::ISO)])) {
                $missing[] = DatePoint::createFromInterface($date);
            }
        }
        $inserted = $this->repository->register($missing);

        $this->logger->info('Регистрация заданий импорта.', [
            'created' => \count($inserted),
            'confirmed' => \count($confirmed),
            'repaired_done' => $requeuedDone,
        ]);

        return new RatesImportResult(
            $period,
            $period->count(),
            \count($confirmed),
            \count($missing) - \count($inserted),
            \count($inserted),
            $this->queries->statistics($period),
            $requeuedDone,
        );
    }
}
