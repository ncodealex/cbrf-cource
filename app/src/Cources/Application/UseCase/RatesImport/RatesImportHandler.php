<?php

declare(strict_types=1);

namespace App\Cources\Application\UseCase\RatesImport;

use App\Cources\Application\Contract\RateImportJobRepositoryInterface;
use App\Cources\Application\Import\RatesImportPeriod;
use App\Shared\Domain\DateFormatInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\DatePoint;

final readonly class RatesImportHandler
{
    public function __construct(private RateImportJobRepositoryInterface $repository, private LoggerInterface $logger = new NullLogger())
    {
    }

    public function __invoke(RatesImportPeriod $period): RatesImportResult
    {
        $requeuedDone = $this->repository->requeueUnconfirmedDone($period);
        $confirmed = [];
        foreach ($this->repository->confirmedDates($period) as $date) {
            $confirmed[$date->format(DateFormatInterface::ISO)] = true;
        }
        $missing = [];
        foreach ($period->dates() as $date) {
            if (!isset($confirmed[$date->format(DateFormatInterface::ISO)])) {
                $missing[] = DatePoint::createFromInterface($date);
            }
        }
        $inserted = $this->repository->register($missing);

        $this->logger->info('Регистрация заданий импорта.', ['created' => \count($inserted), 'confirmed' => \count($confirmed), 'repaired_done' => $requeuedDone]);

        return new RatesImportResult(
            $period,
            $period->count(),
            \count($confirmed),
            \count($missing) - \count($inserted),
            \count($inserted),
            $this->repository->statistics($period),
            $requeuedDone,
        );
    }
}
