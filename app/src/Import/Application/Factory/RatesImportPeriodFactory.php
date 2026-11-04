<?php

declare(strict_types=1);

namespace App\Import\Application\Factory;

use App\Import\Application\DTO\RatesImportPeriodResolution;
use App\Import\Domain\Exception\InvalidRatesImportPeriod;
use App\Import\Domain\ValueObject\RatesImportPeriod;
use App\Shared\Contracts\Rates\RateSetImportGateway;
use App\Shared\Domain\DatePointParser;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\DatePoint;

final readonly class RatesImportPeriodFactory
{
    public function __construct(
        private ClockInterface $clock,
        private RateSetImportGateway $datePolicy,
    ) {
    }

    public function create(
        ?DatePoint $from,
        ?DatePoint $to,
        ?int $days,
    ): RatesImportPeriodResolution {
        if (null !== $days && $days <= 0) {
            throw new InvalidRatesImportPeriod('Число календарных дней должно быть положительным.');
        }
        if ((null !== $from && null !== $to && null !== $days)
            || (null === $days && ((null === $from) !== (null === $to)))) {
            throw new InvalidRatesImportPeriod('Укажите обе границы периода либо число дней с не более чем одной границей.');
        }
        $today = DatePointParser::dateOf($this->clock->now());
        $minimum = DatePointParser::dateOf($this->datePolicy->minRequestDate());
        $from = null === $from ? null : DatePointParser::dateOf($from);
        $to = null === $to ? null : DatePointParser::dateOf($to);
        if (null === $from && null === $to) {
            $to = $today->modify('-1 day');
            $days ??= 180;
        }
        $requestedFrom = $from;
        $requestedTo = $to;
        if (null !== $from && null !== $to && $from > $to) {
            throw new InvalidRatesImportPeriod('Дата начала периода не может быть позже даты окончания.');
        }
        if ($minimum > $today) {
            throw new InvalidRatesImportPeriod('У источника нет доступных дат для импорта.');
        }

        $fromAdjusted = false;
        $toAdjusted = false;
        if (null !== $days) {
            $offset = $days - 1;
            // Сначала сравниваем календарные расстояния, а не прибавляем произвольное число дней.
            if (null !== $from) {
                if ($from > $today || ($from < $minimum && $offset < $this->distance($from, $minimum))) {
                    throw new InvalidRatesImportPeriod('Запрошенный период не пересекается с доступными датами источника.');
                }
                $toAdjusted = $offset > $this->distance($from, $today);
                $to = $toAdjusted ? $today : $from->modify(\sprintf('+%d days', $offset));
            } else {
                if ($to < $minimum || ($to > $today && $offset < $this->distance($today, $to))) {
                    throw new InvalidRatesImportPeriod('Запрошенный период не пересекается с доступными датами источника.');
                }
                $fromAdjusted = $offset > $this->distance($minimum, $to);
                $from = $fromAdjusted ? $minimum : $to->modify(\sprintf('-%d days', $offset));
            }
        }
        if (null === $from || null === $to || $to < $minimum || $from > $today) {
            throw new InvalidRatesImportPeriod('Запрошенный период не пересекается с доступными датами источника.');
        }
        $fromAdjusted = $fromAdjusted || $from < $minimum;
        $toAdjusted = $toAdjusted || $to > $today;
        $period = new RatesImportPeriod($from < $minimum ? $minimum : $from, $to > $today ? $today : $to);

        return new RatesImportPeriodResolution(
            $period,
            $requestedFrom,
            $requestedTo,
            $days,
            $fromAdjusted,
            $toAdjusted,
        );
    }

    private function distance(
        DatePoint $from,
        DatePoint $to,
    ): int {
        return (int) $from->diff($to)->days;
    }
}
