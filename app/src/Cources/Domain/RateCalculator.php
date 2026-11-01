<?php

declare(strict_types=1);

namespace App\Cources\Domain;

use App\Cources\Domain\Entity\RateSet;
use App\Cources\Domain\Exception\ExchangeRate\InvalidRateDateOrder;
use App\Cources\Domain\Exception\ExchangeRate\PreviousRateNotFound;
use App\Cources\Domain\Exception\ExchangeRate\RateNotFoundForDate;
use BcMath\Number;

/**
 * Прямые и кросс-курсы по официальным рублёвым котировкам. Промежуточные значения
 * вычисляются с INTERNAL_SCALE знаками; результат не округляется.
 */
final class RateCalculator
{
    public const int INTERNAL_SCALE = 30;

    /**
     *   Курс за 1 единицу для валютной пары
     *
     * @param RateSet      $set
     * @param CurrencyPair $pair
     *
     * @return Number
     */
    public function rate(RateSet $set, CurrencyPair $pair): Number
    {
        if ($pair->currency->equals($pair->base)) {
            return new Number('1');
        }

        /// Курс одной целевой единицы по отношению к рублю
        $currency = $set->get($pair->currency)->unit();

        if ($pair->base->isRuble()) {
            /// Если базовая валюта это рубль - возвращаем (тк по ЦБ у нас все через рубль )
            return $currency;
        }

        /// Вычисление через кросс-курс
        $cross = $set->get($pair->base)->unit();

        return $currency->div($cross, self::INTERNAL_SCALE);
    }

    /**
     * Текущий и предыдущий курсы считаются независимо;
     * разница сохраняет их точность.
     *
     * @throws RateNotFoundForDate|PreviousRateNotFound
     */
    public function exchangeRate(RateSet $current, RateSet $previous, CurrencyPair $pair): ExchangeRate
    {
        if ($previous->date() >= $current->date()) {
            throw new InvalidRateDateOrder(\sprintf('Предыдущий набор курсов от %s должен предшествовать текущему от %s', $previous->date(), $current->date()));
        }

        /// Курс за запрошенный день
        $rate = $this->rate($current, $pair);

        try {
            /// Курс за предыдущий день
            $previousRate = $this->rate($previous, $pair);
        } catch (RateNotFoundForDate $e) {
            throw new PreviousRateNotFound(
                $e->currency,
                $previous->date(),
                $e
            );
        }
        /// Разница курсов
        $diff = $rate->sub($previousRate);

        return new ExchangeRate(
            currentRateItem: $current->get($pair->currency),
            baseRateItem: $current->get($pair->base),
            effectiveDate: $current->effectiveDate,
            rate: $rate,
            previousEffectiveDate: $previous->effectiveDate,
            previousRate: $previousRate,
            diff: $diff,
        );
    }

}
