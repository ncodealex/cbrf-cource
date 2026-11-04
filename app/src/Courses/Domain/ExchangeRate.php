<?php

declare(strict_types=1);

namespace App\Courses\Domain;

use App\Courses\Domain\Entity\RateItem;
use BcMath\Number;
use Symfony\Component\Clock\DatePoint;

/**
 * Курс и разница без округления. Для рубля RateItem содержит условную котировку 1:1.
 */
final readonly class ExchangeRate
{
    public function __construct(
        public RateItem $currentRateItem,
        public RateItem $baseRateItem,
        public DatePoint $effectiveDate,
        public Number $rate,
        public DatePoint $previousEffectiveDate,
        public Number $previousRate,
        public Number $diff,
    ) {
    }
}
