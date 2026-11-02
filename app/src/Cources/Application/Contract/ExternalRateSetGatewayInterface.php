<?php

declare(strict_types=1);

namespace App\Cources\Application\Contract;

use App\Cources\Domain\Entity\RateSet;
use Symfony\Component\Clock\DatePoint;

/**
 * Запрос котировок во внешнем источнике.
 */
interface ExternalRateSetGatewayInterface
{
    /**
     *  Запросить котировки во внешнем API.
     */
    public function fetch(DatePoint $requestDate): RateSet;

    /**
     *  Получить последние зарегистрированные котировки.
     */
    public function fetchLatest(): RateSet;
}
