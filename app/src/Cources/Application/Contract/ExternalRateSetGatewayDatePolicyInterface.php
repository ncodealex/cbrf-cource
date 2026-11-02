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
    public function minRequestDate(): DatePoint;

    public function assertAllowed(DatePoint $date): void;
}
