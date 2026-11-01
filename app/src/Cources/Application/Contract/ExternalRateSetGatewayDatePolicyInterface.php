<?php
declare(strict_types=1);

namespace App\Cources\Application\Contract;

use App\Cources\Application\Exception\GatewayNotSupportRateSetDateInterface;
use Symfony\Component\Clock\DatePoint;

/**
 * Политика возможности расчета котировок (для учета возможностей шлюза)
 *
 *  Например, ЦБ РФ предоставляет котировки с 1992-07-01
 */
interface ExternalRateSetGatewayDatePolicyInterface
{
    /**
     * @param DatePoint $date
     *
     * @return void
     *
     * @throws GatewayNotSupportRateSetDateInterface
     */
    public function assertAllowed(DatePoint $date): void;
}
