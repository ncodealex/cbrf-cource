<?php

declare(strict_types=1);

namespace App\Cources\Application\Contract;

use App\Cources\Application\Exception\InvalidRateImportTransition;
use App\Cources\Application\Import\RateImportAction;
use App\Cources\Application\Import\RateImportClaim;
use App\Cources\Application\Import\RateImportErrorList;
use App\Cources\Application\Import\RateImportProgress;
use App\Cources\Application\Import\RateImportStatistics;
use App\Cources\Application\Import\RatesImportPeriod;
use App\Cources\Application\Message\ProcessRateImportMessage;
use Symfony\Component\Clock\DatePoint;

/**
 * Хранение заданий импорта с версионным ограждением конкурентных обработчиков.
 *
 * Подтверждённая дата определяется по сохранённой связи requestedDate → effectiveDate
 * и имеет приоритет над техническим статусом задания.
 */
interface RateImportJobRepositoryInterface
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
     * Поставить новые задания на импорт, пропустив уже существующие.
     *
     * @param list<DatePoint> $dates календарные даты для регистрации
     *
     * @return list<DatePoint> фактически зарегистрированные даты
     */
    public function register(array $dates): array;

    /**
     * Подтверждение даты имеет приоритет над статусом задания; остальные даты учитываются по статусу.
     *
     * @return RateImportStatistics количество подтверждённых дат и заданий в каждом статусе
     */
    public function statistics(RatesImportPeriod $period): RateImportStatistics;

    /**
     * Вернуть неподтверждённые Done в Waiting и увеличить их версию.
     *
     * Повторный вызов не изменяет уже восстановленные задания.
     *
     * @return int количество восстановленных заданий
     *
     * @throws InvalidRateImportTransition если политика переходов не разрешает восстановление Done
     */
    public function requeueUnconfirmedDone(RatesImportPeriod $period): int;

    /**
     * Сбросить существующие задания в Waiting с увеличением версии, не создавая новых строк.
     */
    public function resetForRefresh(DatePoint ...$dates): void;

    /**
     * Физически удалить задания вместе с историей их версий.
     *
     * Не использовать для штатного восстановления или Refresh.
     */
    public function delete(DatePoint ...$dates): void;

    /**
     * Зарезервировать готовые к публикации задания в пределах глобальной ёмкости диспетчера.
     *
     * @return list<ProcessRateImportMessage> сообщения с датой и текущей версией задания
     */
    public function reserveDispatch(int $limit, int $redispatchAfter, int $maxUnclaimed): array;

    /**
     * Захватить готовое задание, если переданная версия ещё актуальна.
     */
    public function claim(string $date, int $version, int $leaseSeconds): ?RateImportClaim;

    /**
     * Проверить наличие сохранённой связи набора курсов для календарной даты.
     *
     * @phpstan-impure
     */
    public function isConfirmed(string $date): bool;

    /**
     * Завершить принадлежащее обработчику задание с проверкой статуса и версии.
     *
     * @return bool true, если переход применён к актуальной версии задания
     *
     * @throws InvalidRateImportTransition если действие нельзя применить к Processing
     * @throws \InvalidArgumentException   если действие не является вариантом завершения задания
     */
    public function finish(
        RateImportClaim $claim,
        RateImportAction $action,
        int $attempts,
        ?DatePoint $availableAt = null,
        ?string $errorCode = null,
        ?string $errorText = null,
    ): bool;

    /**
     * Получить задания с истёкшей арендой для последующего атомарного восстановления.
     *
     * @return list<RateImportClaim> снимки найденных заданий
     */
    public function expired(int $limit): array;

    /**
     * Восстановить задание с истёкшей арендой, если его статус и версия не изменились.
     *
     * @return bool true, если восстановление применено
     *
     * @throws InvalidRateImportTransition если политика переходов не разрешает восстановление Processing
     */
    public function recover(RateImportClaim $claim, int $attempts, ?DatePoint $retryAt): bool;

    /**
     * Получить терминальные ошибки импорта с необязательным отбором по дате.
     */
    public function errors(?string $date, int $limit): RateImportErrorList;

    /**
     * Вернуть ошибочные задания в Waiting с увеличением версии.
     *
     * @return int количество переведённых заданий
     *
     * @throws InvalidRateImportTransition если выбранное задание не находится в Error
     * @throws \InvalidArgumentException   если задание выбранной даты отсутствует
     */
    public function retryErrors(?string $date): int;

    /**
     * Получить полный снимок прогресса по каждой календарной дате периода.
     *
     * Подтверждённые даты учитываются независимо от статуса задания; отдельно возвращаются
     * неподтверждённые Done и даты без задания.
     */
    public function progress(RatesImportPeriod $period): RateImportProgress;
}
