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
     * Подтверждение даты имеет приоритет над статусом задания; остальные даты учитываются по статусу.
     *
     * @return ImportStatistics
     */
    public function statistics(RatesImportPeriod $period): array;

    /** Возвращает неподтверждённые Done в Waiting и увеличивает версию; повторный вызов их не изменяет. */
    public function requeueUnconfirmedDone(RatesImportPeriod $period): int;

    /** Сбрасывает существующие задания в Waiting с увеличением версии, не создавая новых строк. */
    public function resetForRefresh(DatePoint ...$dates): void;

    /** Физическое удаление сбрасывает историю версии; не использовать в штатном восстановлении и Refresh. */
    public function delete(DatePoint ...$dates): void;
}
