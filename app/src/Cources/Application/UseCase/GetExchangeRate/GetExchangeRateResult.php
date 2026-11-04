<?php

declare(strict_types=1);

namespace App\Cources\Application\UseCase\GetExchangeRate;

use App\Cources\Domain\CurrencyPair;
use App\Cources\Domain\ExchangeRate;
use App\Cources\Domain\ExchangeRateCalculator;
use App\Cources\Domain\ValueObject\CurrencyCode;
use BcMath\Number;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Курс на дату запроса, предыдущий курс и изменение с округлением для вывода.
 * Исходная котировка ЦБ хранится отдельно от курса одной единицы валюты.
 */
#[Exclude]
final readonly class GetExchangeRateResult
{
    public function __construct(
        public DatePoint $requestedDate,
        public CurrencyCode $currency,
        public CurrencyCode $base,
        public string $currencyName,
        public int $nominal,
        public Number $sourceValue,
        public DatePoint $effectiveDate,
        public Number $rate,
        public DatePoint $previousEffectiveDate,
        public Number $previousRate,
        public Number $diff,
    ) {
    }

    public static function fromDomain(
        DatePoint $requestedDate,
        CurrencyPair $pair,
        ExchangeRate $exchangeRate,
    ): self {
        $item = $exchangeRate->currentRateItem;
        $scale = ($pair->currency->isRuble() ? $exchangeRate->baseRateItem : $item)->sourceScale()
            ?? ExchangeRateCalculator::INTERNAL_SCALE;

        return new self(
            $requestedDate,
            $pair->currency,
            $pair->base,
            $item->name,
            $item->nominal,
            $item->value,
            $exchangeRate->effectiveDate,
            $exchangeRate->rate->round($scale),
            $exchangeRate->previousEffectiveDate,
            $exchangeRate->previousRate->round($scale),
            $exchangeRate->diff->round($scale),
        );
    }
}
