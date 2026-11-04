<?php

declare(strict_types=1);

namespace App\Cources\Application\Contract;

use App\Cources\Domain\Entity\RateSet;
use App\Support\Retry\Application\RetryableFailure;
use Symfony\Component\Clock\DatePoint;

/**
 * Запрос котировок во внешнем источнике.
 */
interface ExternalRateSetGatewayInterface
{
    /**
     * Запросить котировки на календарную дату во внешнем API.
     *
     * @throws RetryableFailure если внешний источник временно недоступен или ограничил запросы
     */
    public function fetch(DatePoint $requestDate): RateSet;

    /**
     * Получить последние зарегистрированные котировки во внешнем API.
     *
     * @throws RetryableFailure если внешний источник временно недоступен или ограничил запросы
     */
    public function fetchLatest(): RateSet;
}
