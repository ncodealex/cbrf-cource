<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Rates;

use Symfony\Component\Clock\DatePoint;

interface RateSetImportGateway
{
    /**
     * Обеспечить календарную связь в PostgreSQL, даже при наличии только кеша.
     * Необязательная версия ограждает запись от Refresh после начала HTTP.
     *
     * @throws \Throwable при отказе источника, хранения либо устаревании исполнения
     */
    public function ensureAvailable(
        DatePoint $requestedDate,
        ?int $executionVersion = null,
    ): void;

    /**
     * Проверить подтверждённую связь непосредственно в PostgreSQL.
     *
     * @phpstan-impure
     */
    public function isConfirmed(DatePoint $requestedDate): bool;

    /**
     * Получить подтверждённые даты замкнутого периода в календарном порядке.
     *
     * @return list<DatePoint> даты с сохранённой связью в PostgreSQL
     */
    public function confirmedDates(
        DatePoint $from,
        DatePoint $to,
    ): array;

    /**
     * Нижняя календарная граница действующей политики источника.
     */
    public function minRequestDate(): DatePoint;
}
