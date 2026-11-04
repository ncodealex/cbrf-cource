<?php

declare(strict_types=1);

namespace App\Cources\Domain\Exception\ExchangeRate;

final class PreviousRateNotFound extends ExchangeRateException
{
    public function __construct(
        public readonly string $currency,
        public readonly string $previousEffectiveDate,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf('В предыдущем наборе от %s нет курса %s', $previousEffectiveDate, $currency),
            0,
            $previous,
        );
    }
}
