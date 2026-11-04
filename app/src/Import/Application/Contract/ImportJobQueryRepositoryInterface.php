<?php

declare(strict_types=1);

namespace App\Import\Application\Contract;

use App\Import\Application\DTO\RateImportErrorList;
use App\Import\Application\DTO\RateImportProgress;
use App\Import\Application\DTO\RateImportStatistics;
use App\Import\Domain\Entity\ImportJob;
use App\Import\Domain\ValueObject\RatesImportPeriod;
use Symfony\Component\Clock\DatePoint;

/**
 * Чтение заданий и согласованных снимков состояния PostgreSQL без изменения заданий.
 */
interface ImportJobQueryRepositoryInterface
{
    /**
     * Получить подтверждённые календарные даты внутри периода включительно.
     *
     * Подтверждёнными считаются даты, для которых уже сохранена связь с набором курсов.
     *
     * @return list<DatePoint> подтверждённые даты периода
     */
    public function confirmedDates(RatesImportPeriod $period): array;

    /**
     * Подтверждение даты имеет приоритет над статусом задания; остальные даты учитываются по статусу.
     *
     * @return RateImportStatistics количество подтверждённых дат и заданий в каждом статусе
     */
    public function statistics(RatesImportPeriod $period): RateImportStatistics;

    /**
     * Проверить наличие сохранённой связи набора курсов для календарной даты.
     *
     * @phpstan-impure
     */
    public function isConfirmed(string $date): bool;

    /**
     * @phpstan-impure
     *
     * Получить задания с истёкшей арендой для последующего атомарного восстановления.
     *
     * @return list<ImportJob> снимки найденных заданий
     */
    public function expired(int $limit): array;

    /**
     * Получить терминальные ошибки импорта с необязательным отбором по дате.
     */
    public function errors(
        ?string $date,
        int $limit,
    ): RateImportErrorList;

    /**
     * Получить полный снимок прогресса по каждой календарной дате периода.
     *
     * Подтверждённые даты учитываются независимо от статуса задания; отдельно возвращаются
     * неподтверждённые Done и даты без задания.
     */
    public function progress(RatesImportPeriod $period): RateImportProgress;
}
