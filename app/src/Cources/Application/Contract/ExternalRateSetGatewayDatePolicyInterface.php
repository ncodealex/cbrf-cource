<?php

declare(strict_types=1);

namespace App\Cources\Application\Contract;

use Symfony\Component\Clock\DatePoint;

/**
 * Политика возможности расчета котировок (для учета возможностей шлюза).
 *
 *  Минимальную доступную дату определяет реализация источника.
 */
interface ExternalRateSetGatewayDatePolicyInterface
{
    /**
     * Получить нижнюю календарную границу, поддерживаемую текущим источником.
     */
    public function minRequestDate(): DatePoint;

    /**
     * Проверить доступность календарной даты без запроса котировок и изменения хранилища.
     *
     * @throws \Throwable&\App\Cources\Application\Exception\GatewayNotSupportRateSetDateInterface если дата недоступна
     */
    public function assertAllowed(DatePoint $date): void;
}
