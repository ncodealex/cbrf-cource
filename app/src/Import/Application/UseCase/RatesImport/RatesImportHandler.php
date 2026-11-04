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
use function count;

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

        // 1. Вернуть в очередь Done без подтверждённой календарной даты.
        $requeuedDone = $this->repository->requeueUnconfirmedDone($period);

        // 2. Собрать даты, уже подтверждённые в хранилище.
        $confirmed = [];
        foreach ($this->queries->confirmedDates($period) as $date) {
            $confirmed[$date->format(DateFormatInterface::ISO)] = true;
        }
        // 3. Найти даты периода без подтверждения.
        $missing = [];
        foreach ($period->dates() as $date) {
            if (!isset($confirmed[$date->format(DateFormatInterface::ISO)])) {
                $missing[] = DatePoint::createFromInterface($date);
            }
        }
        // 4. Зарегистрировать недостающие задания.
        $inserted = $this->repository->register($missing);

        // 5. Записать итог и вернуть состояние периода.
        $this->logger->info('Регистрация заданий импорта.', [
            'created'       => count($inserted),
            'confirmed'     => count($confirmed),
            'repaired_done' => $requeuedDone,
        ]);

        return new RatesImportResult(
            $period,
            $period->count(),
            count($confirmed),
            count($missing) - count($inserted),
            count($inserted),
            $this->queries->statistics($period),
            $requeuedDone,
        );
    }
}
