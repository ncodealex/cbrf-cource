<?php

declare(strict_types=1);

namespace App\Import\Application\DTO;

use App\Import\Domain\ValueObject\RatesImportPeriod;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Результат разрешения периода: итоговые границы и сведения об их корректировке относительно запроса.
 */
#[Exclude]
final readonly class RatesImportPeriodResolution
{
    /**
     * Исходные границы — опорные даты запроса, без вычисления недоступной границы при огромном числе дней.
     */
    public function __construct(
        public RatesImportPeriod $period,
        public ?DatePoint $requestedFrom,
        public ?DatePoint $requestedTo,
        public ?int $requestedDays,
        public bool $fromAdjusted,
        public bool $toAdjusted,
    ) {
    }

    public function wasAdjusted(): bool
    {
        return $this->fromAdjusted || $this->toAdjusted;
    }
}
