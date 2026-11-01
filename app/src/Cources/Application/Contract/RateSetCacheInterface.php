<?php

declare(strict_types=1);

namespace App\Cources\Application\Contract;

use App\Cources\Application\Exception\RateSetCacheFailure;
use App\Cources\Application\Exception\RateSetNotFound;
use App\Cources\Domain\Entity\RateSet;
use Symfony\Component\Clock\DatePoint;

/**
 * Получить котировки из кеша.
 */
interface RateSetCacheInterface
{
    /**
     * @throws RateSetNotFound
     * @throws RateSetCacheFailure
     */
    public function get(DatePoint $requestDate): RateSet;

    /**
     * @throws RateSetCacheFailure
     */
    public function put(DatePoint $requestDate, RateSet $set): void;

    /**
     * Удалить кеш набора для одной календарной даты.
     *
     * @throws RateSetCacheFailure
     */
    public function delete(DatePoint ...$requestDates): void;
}
