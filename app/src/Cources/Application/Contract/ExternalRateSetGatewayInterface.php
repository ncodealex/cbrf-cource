<?php

declare(strict_types=1);

namespace App\Cources\Application\Contract;

use App\Cources\Application\Exception\CbrGatewayException;
use App\Cources\Domain\Entity\RateSet;
use Symfony\Component\Clock\DatePoint;

/**
 * Запрос котировок во внешнем источнике.
 */
interface ExternalRateSetGatewayInterface
{
    /**
     *  Запросить котировки во внешнем API.
     *
     * @throws CbrGatewayException
     */
    public function fetch(DatePoint $requestDate): RateSet;

    /**
     *  Получить последние зарегистрированные котировки.
     *
     * @throws CbrGatewayException
     */
    public function fetchLatest(): RateSet;
}
