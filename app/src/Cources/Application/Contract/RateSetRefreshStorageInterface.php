<?php

declare(strict_types=1);

namespace App\Cources\Application\Contract;

use Symfony\Component\Clock\DatePoint;

interface RateSetRefreshStorageInterface
{
    /**
     * Атомарно сбрасывает существующие задания указанных дат и удаляет набор с календарными связями.
     * Кеш и повторное получение котировок не входят в эту операцию PostgreSQL.
     * При отсутствии relatedDates сбрасывается задание requestDate; новые задания не создаются.
     *
     * @throws \Throwable при отказе хранения; сброс заданий и удаление откатываются вместе
     */
    public function invalidate(
        DatePoint $requestDate,
        DatePoint ...$relatedDates,
    ): void;
}
