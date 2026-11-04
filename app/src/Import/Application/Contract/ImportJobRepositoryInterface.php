<?php

declare(strict_types=1);

namespace App\Import\Application\Contract;

use App\Import\Application\Message\ProcessRateImportMessage;
use App\Import\Domain\Entity\ImportJob;
use App\Import\Domain\Enum\RateImportAction;
use App\Import\Domain\Exception\InvalidRateImportTransition;
use App\Import\Domain\ValueObject\ImportJobExecution;
use App\Import\Domain\ValueObject\RatesImportPeriod;
use Symfony\Component\Clock\DatePoint;

/**
 * Хранение заданий импорта с версионным ограждением конкурентных обработчиков.
 *
 * Подтверждённая дата определяется по сохранённой связи requestedDate → effectiveDate
 * и имеет приоритет над техническим статусом задания.
 */
interface ImportJobRepositoryInterface
{
    /**
     * Поставить новые задания на импорт, пропустив уже существующие.
     *
     * @param list<DatePoint> $dates календарные даты для регистрации
     *
     * @return list<DatePoint> фактически зарегистрированные даты
     */
    public function register(array $dates): array;

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
    public function reserveDispatch(
        int $limit,
        int $redispatchAfter,
        int $maxUnclaimed,
    ): array;

    /**
     * Захватить готовое задание, если переданная версия ещё актуальна.
     */
    public function claim(
        string $date,
        int $version,
        int $leaseSeconds,
    ): ?ImportJobExecution;

    /**
     * Завершить принадлежащее обработчику задание с проверкой статуса и версии.
     *
     * @return bool true, если переход применён к актуальной версии задания
     *
     * @throws InvalidRateImportTransition если действие нельзя применить к Processing
     * @throws \InvalidArgumentException   если действие не является вариантом завершения задания
     */
    public function finish(
        ImportJobExecution $claim,
        RateImportAction $action,
        int $attempts,
        ?DatePoint $availableAt = null,
        ?string $errorCode = null,
        ?string $errorText = null,
    ): bool;

    /**
     * Восстановить задание с истёкшей арендой, если его статус и версия не изменились.
     *
     * @return bool true, если восстановление применено
     *
     * @throws InvalidRateImportTransition если политика переходов не разрешает восстановление Processing
     */
    public function recover(
        ImportJob $claim,
        int $attempts,
        ?DatePoint $retryAt,
    ): bool;

    /**
     * Вернуть ошибочные задания в Waiting с увеличением версии.
     *
     * @return int количество переведённых заданий
     *
     * @throws InvalidRateImportTransition если выбранное задание не находится в Error
     * @throws \InvalidArgumentException   если задание выбранной даты отсутствует
     */
    public function retryErrors(?string $date): int;
}
