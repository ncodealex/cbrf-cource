<?php

declare(strict_types=1);

namespace App\Cources\Domain;

use App\Cources\Domain\ValueObject\CurrencyCode;

/**
 * Валютная пара: сколько единиц base стоит одна единица currency.
 */
final readonly class CurrencyPair implements \Stringable
{
    public function __construct(
        public CurrencyCode $currency,
        public CurrencyCode $base,
    ) {
    }

    /**
     * Курс публикуется ЦБ напрямую (X/RUB) или тривиален (X/X),
     * поэтому округление не требуется.
     */
    public function isExact(): bool
    {
        return $this->base->isRuble() || $this->currency->equals($this->base);
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string
    {
        return $this->currency.'/'.$this->base;
    }
}
