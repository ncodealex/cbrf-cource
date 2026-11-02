<?php

declare(strict_types=1);

namespace App\Cources\Application\Import;

enum RateImportStatus: string
{
    case Waiting = 'waiting';
    case Processing = 'processing';
    case Deferred = 'deferred';
    case Done = 'done';
    case Error = 'error';

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
}
