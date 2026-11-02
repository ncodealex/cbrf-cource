<?php

declare(strict_types=1);

namespace App\Cources\Application\Factory;

use App\Cources\Application\Contract\ExternalRateSetGatewayDatePolicyInterface;
use App\Cources\Application\Exception\GatewayNotSupportRateSetDateInterface;
use App\Cources\Application\Exception\InvalidRatesImportPeriod;
use App\Cources\Application\Import\RatesImportPeriod;
use App\Shared\Domain\DatePointParser;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\DatePoint;

final readonly class RatesImportPeriodFactory
{
    public function __construct(
        private ClockInterface $clock,
        private ExternalRateSetGatewayDatePolicyInterface $datePolicy,
    ) {
    }

    public function create(?DatePoint $from, ?DatePoint $to, ?int $days): RatesImportPeriod
    {
        if (null !== $days && $days <= 0) {
            throw new InvalidRatesImportPeriod('Число календарных дней должно быть положительным.');
        }
        if ((null !== $from && null !== $to && null !== $days)
            || (null === $days && ((null === $from) !== (null === $to)))) {
            throw new InvalidRatesImportPeriod('Укажите обе границы периода либо число дней с не более чем одной границей.');
        }

        $yesterday = DatePointParser::dateOf($this->clock->now())->modify('-1 day');
        $from = null === $from ? null : DatePointParser::dateOf($from);
        $to = null === $to ? null : DatePointParser::dateOf($to);

        if ((null !== $from && $from > $yesterday) || (null !== $to && $to > $yesterday)) {
            throw new InvalidRatesImportPeriod('Границы периода должны быть не позднее вчерашнего дня по Москве.');
        }

        // Верхняя граница длины защищает календарную арифметику от переполнения.
        // Фактическую доступность дат определяет политика внешнего источника.
        $maxDays = (int) DatePointParser::parse('0001-01-01')->diff($to ?? $yesterday)->days + 1;
        if (null !== $days && $days > $maxDays) {
            throw new InvalidRatesImportPeriod('Число дней превышает допустимую длину календарного периода.');
        }

        if (null === $from && null === $to) {
            $to = $yesterday;
            $days ??= 180;
        }
        if (null !== $days) {
            $offset = $days - 1;
            if (null !== $from) {
                $to = $from->modify(\sprintf('+%d days', $offset));
            } else {
                $from = $to->modify(\sprintf('-%d days', $offset));
            }
        }
        if (null === $from || null === $to) {
            throw new InvalidRatesImportPeriod('Не удалось определить границы периода.');
        }

        $period = new RatesImportPeriod($from, $to);
        if ($period->to > $yesterday) {
            throw new InvalidRatesImportPeriod('Дата окончания периода должна быть не позднее вчерашнего дня по Москве.');
        }
        try {
            $this->datePolicy->assertAllowed($period->from);
            $this->datePolicy->assertAllowed($period->to);
        } catch (GatewayNotSupportRateSetDateInterface $e) {
            throw new InvalidRatesImportPeriod('Период выходит за пределы доступных дат источника котировок.', previous: $e);
        }

        return $period;
    }
}
