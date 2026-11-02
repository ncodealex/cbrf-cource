<?php

declare(strict_types=1);

namespace App\Cources\Application\Contract;

use App\Cources\Application\Import\RateImportAction;
use App\Cources\Application\Import\RateImportClaim;
use App\Cources\Application\Import\RatesImportPeriod;
use App\Cources\Application\Message\ProcessRateImportMessage;
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

    /** @return list<ProcessRateImportMessage> */
    public function reserveDispatch(int $limit, int $redispatchAfter, int $maxUnclaimed): array;

    public function claim(string $date, int $version, int $leaseSeconds): ?RateImportClaim;

    /** @phpstan-impure */
    public function isConfirmed(string $date): bool;

    public function finish(RateImportClaim $claim, RateImportAction $action, int $attempts, ?DatePoint $availableAt = null, ?string $errorCode = null, ?string $errorText = null): bool;

    /** @return list<RateImportClaim> */
    public function expired(int $limit): array;

    public function recover(RateImportClaim $claim, int $attempts, ?DatePoint $retryAt): bool;

    /** @return array{total: int, rows: list<array{requested_date: string, attempts: int, error_code: string, error_text: string}>} */
    public function errors(?string $date, int $limit): array;

    public function retryErrors(?string $date): int;

    /** @return array{confirmed: int, waiting: int, processing: int, deferred: int, error: int, inconsistentDone: int, missing: int} */
    public function progress(RatesImportPeriod $period): array;
}
