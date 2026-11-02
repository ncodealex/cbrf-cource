<?php

declare(strict_types=1);

namespace App\Cources\Application\Contract;

use App\Cources\Application\Import\RatesImportPeriod;
use Symfony\Component\Clock\DatePoint;

/**
 * Хранение заданий без автоматического изменения существующих статусов.
 *
 * @phpstan-type ImportStatistics array{waiting: int, processing: int, deferred: int, done: int, error: int}
 */
interface RateImportJobRepositoryInterface
{
    /** @return list<DatePoint> */
    public function confirmedDates(RatesImportPeriod $period): array;

    /**
     * @param list<DatePoint> $dates
     *
     * @return list<DatePoint> фактически зарегистрированные даты
     */
    public function register(array $dates): array;

    /**
     * Подтверждение даты имеет приоритет над статусом задания; устаревший Done не учитывается.
     *
     * @return ImportStatistics
     */
    public function statistics(RatesImportPeriod $period): array;

    public function delete(DatePoint ...$dates): void;
}
