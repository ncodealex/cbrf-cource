<?php

declare(strict_types=1);

namespace App\Import\Domain\Enum;

use App\Import\Domain\Exception\InvalidRateImportTransition;

/**
 * Состояния задания импорта и разрешённые действия для каждого состояния.
 */
enum RateImportStatus: string
{

    /**
     * Задание зарегистрировано и ожидает захвата диспетчером и Worker.
     */
    case Waiting = 'waiting';
    /**
     * Worker захватил задание и выполняет импорт под lease.
     */
    case Processing = 'processing';
    /**
     * Повтор отложен до доступного времени после временного сбоя.
     */
    case Deferred = 'deferred';
    /**
     * Календарная дата подтверждена в хранилище курсов.
     */
    case Done = 'done';
    /**
     * Попытки исчерпаны или ошибка признана постоянной; требуется ручной повтор.
     */
    case Error = 'error';

    /**
     * Исходные состояния действия для конкурентных операций хранения.
     *
     * @return list<self> все состояния, разрешающие действие
     */
    public static function allowedFor(RateImportAction $action): array
    {
        return array_values(array_filter(self::cases(), static fn(self $status): bool => $status->allows($action)));
    }

    public function allows(RateImportAction $action): bool
    {
        return match ($action) {
            RateImportAction::Claim => self::Waiting === $this || self::Deferred === $this,
            RateImportAction::Complete, RateImportAction::Defer, RateImportAction::Fail, RateImportAction::Recover => self::Processing === $this,
            RateImportAction::Refresh => true,
            RateImportAction::RepairDone => self::Done === $this,
            RateImportAction::RetryError => self::Error === $this,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Ожидает обработки',
            self::Processing => 'Обрабатывается',
            self::Deferred => 'Отложено',
            self::Done => 'Завершено',
            self::Error => 'Ошибка',
        };
    }

    public function assertAllows(RateImportAction $action): void
    {
        if (!$this->allows($action)) {
            throw new InvalidRateImportTransition($this, $action);
        }
    }


}
