<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Import;

use Symfony\Component\Clock\DatePoint;

interface ImportJobResetter
{
    /**
     * Сбросить существующие задания с увеличением версий в транзакции вызывающего.
     * Реализация обязана использовать общий PostgreSQL Connection с хранилищем курсов.
     *
     * @throws \Throwable при отказе PostgreSQL; транзакцию откатывает вызывающий
     */
    public function resetForRefresh(DatePoint ...$requestedDates): void;
}
