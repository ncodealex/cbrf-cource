<?php

declare(strict_types=1);

namespace App\Import\Domain\Entity;

use App\Import\Domain\Enum\RateImportAction;
use App\Import\Domain\Enum\RateImportStatus;
use App\Import\Domain\ValueObject\ImportJobExecution;
use Symfony\Component\Clock\DatePoint;

/**
 * Жизненный цикл одной календарной даты. Время и решение о повторе передаёт сценарий.
 * Изменения версии требуют повторной проверки fencing token при сохранении в БД.
 */
final class ImportJob
{
    public function __construct(
        public readonly DatePoint $requestedDate,
        public private(set) RateImportStatus $status = RateImportStatus::Waiting,
        public private(set) int $version = 0,
        public private(set) int $attempts = 0,
        public private(set) ?DatePoint $availableAt = null,
        public private(set) ?DatePoint $leaseUntil = null,
        public private(set) ?DatePoint $dispatchedAt = null,
        public private(set) ?DatePoint $completedAt = null,
        public private(set) ?string $errorCode = null,
        public private(set) ?string $errorText = null,
    ) {
        if (
            $version < 0
            || $attempts < 0
            || (RateImportStatus::Processing === $status && null === $leaseUntil)
            || (RateImportStatus::Deferred === $status && null === $availableAt)) {
            throw new \InvalidArgumentException('Некорректное состояние задания импорта.');
        }
    }

    /**
     * Захватывает доступное задание с будущим lease и выдаёт новую версию исполнения.
     */
    public function claim(
        DatePoint $now,
        DatePoint $leaseUntil,
    ): ImportJobExecution {
        $this->status->assertAllows(RateImportAction::Claim);
        if ($this->availableAt > $now || $leaseUntil <= $now) {
            throw new \LogicException('Задание ещё недоступно либо срок владения уже истёк.');
        }
        $this->status = RateImportStatus::Processing;
        $this->leaseUntil = $leaseUntil;
        ++$this->version;

        return new ImportJobExecution($this->requestedDate, $this->version, $this->attempts);
    }

    /**
     * Подтверждение даты обязан проверить вызывающий внутри транзакции сохранения.
     */
    public function complete(
        DatePoint $now,
        bool $confirmed,
        int $attempts,
    ): void {
        $this->status->assertAllows(RateImportAction::Complete);
        if (!$confirmed) {
            throw new \LogicException('Дата не подтверждена в хранилище курсов.');
        }
        $this->finish(
            RateImportStatus::Done,
            $attempts,
            $now,
            null,
            null,
        );
        $this->completedAt = $now;
    }

    private function finish(
        RateImportStatus $status,
        int $attempts,
        DatePoint $availableAt,
        ?string $code,
        ?string $text,
    ): void {
        if ($attempts < 0) {
            throw new \InvalidArgumentException('Число попыток не может быть отрицательным.');
        }
        $this->status = $status;
        $this->attempts = $attempts;
        $this->availableAt = $availableAt;
        $this->leaseUntil = null;
        $this->dispatchedAt = null;
        $this->completedAt = null;
        $this->errorCode = $code;
        $this->errorText = $text;
        ++$this->version;
    }

    /**
     * Применяет уже принятое решение о повторе; AdmissionFailure может не расходовать попытку.
     */
    public function defer(
        DatePoint $retryAt,
        int $attempts,
        ?string $code,
        ?string $text,
    ): void {
        $this->status->assertAllows(RateImportAction::Defer);
        $this->finish(RateImportStatus::Deferred, $attempts, $retryAt, $code, $text);
    }

    public function fail(
        DatePoint $now,
        int $attempts,
        ?string $code,
        ?string $text,
    ): void {
        $this->status->assertAllows(RateImportAction::Fail);
        $this->finish(
            RateImportStatus::Error,
            $attempts,
            $now,
            $code,
            $text,
        );
    }

    /**
     * Применяет решение RetryPolicy только после истечения текущего lease.
     */
    public function recover(
        DatePoint $now,
        int $attempts,
        ?DatePoint $retryAt,
        string $code,
    ): void {
        $this->status->assertAllows(RateImportAction::Recover);

        if (null === $this->leaseUntil || $this->leaseUntil > $now) {
            throw new \LogicException('Срок владения заданием ещё не истёк.');
        }

        $status = null === $retryAt
            ? RateImportStatus::Error
            : RateImportStatus::Deferred;

        $this->finish(
            $status,
            $attempts,
            $retryAt ?? $now,
            $code,
            'Истёк срок владения заданием.',
        );
    }

    /**
     * Сбрасывает попытки и ошибку, увеличивает version и инвалидирует прежнего исполнителя.
     */
    public function resetForRefresh(DatePoint $now): void
    {
        $this->status->assertAllows(RateImportAction::Refresh);
        $this->reset($now);
    }

    private function reset(DatePoint $now): void
    {
        $this->finish(
            RateImportStatus::Waiting,
            0,
            $now,
            null,
            null,
        );
    }

    /**
     * Восстанавливает Done только при отсутствии сохранённой календарной связи.
     */
    public function repairDone(
        DatePoint $now,
        bool $confirmed,
    ): void {
        $this->status->assertAllows(RateImportAction::RepairDone);
        if ($confirmed) {
            throw new \LogicException('Подтверждённое задание не требует восстановления.');
        }
        $this->reset($now);
    }

    /**
     * Начинает новый цикл попыток после ручного восстановления терминальной ошибки.
     */
    public function retryError(DatePoint $now): void
    {
        $this->status->assertAllows(RateImportAction::RetryError);
        $this->reset($now);
    }
}
