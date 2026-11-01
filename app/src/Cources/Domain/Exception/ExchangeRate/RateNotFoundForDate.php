<?php

declare(strict_types=1);

namespace App\Cources\Domain\Exception\ExchangeRate;

use function sprintf;

final class RateNotFoundForDate extends ExchangeRateException
{
    public function __construct(
        public readonly string $currency,
        public readonly string $effectiveDate
    ) {
        parent::__construct(sprintf('Не найден установленный курс %s в наборе от %s', $currency, $effectiveDate));
    }
}
