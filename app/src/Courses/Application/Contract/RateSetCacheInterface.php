<?php

declare(strict_types=1);

namespace App\Courses\Application\Contract;

use App\Courses\Application\Exception\RateSetCacheFailure;
use App\Courses\Application\Exception\RateSetNotFound;
use App\Courses\Domain\Entity\RateSet;
use Symfony\Component\Clock\DatePoint;

/**
 * Получить котировки из кеша.
 */
interface RateSetCacheInterface
{
    /**
     * Прочитать полный набор для точной календарной даты запроса.
     *
     * @throws RateSetNotFound     если ключ отсутствует
     * @throws RateSetCacheFailure если кеш недоступен или содержимое повреждено
     */
    public function get(DatePoint $requestDate): RateSet;

    /**
     * Сохранить полный набор для календарной даты с настроенным TTL, заменяя прежний ключ.
     *
     * @throws RateSetCacheFailure если набор не удалось записать
     */
    public function put(
        DatePoint $requestDate,
        RateSet $set,
    ): void;

    /**
     * Удалить ключи всех переданных календарных дат; отсутствующие ключи пропускаются.
     *
     * @throws RateSetCacheFailure
     */
    public function delete(DatePoint ...$requestDates): void;
}
