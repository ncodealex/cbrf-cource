<?php

declare(strict_types=1);

namespace App\Cources\Application\Import;

use App\Cources\Application\Exception\InvalidRatesImportPeriod;
use App\Shared\Domain\DatePointParser;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class RatesImportPeriod
{
    public DatePoint $from;
    public DatePoint $to;

    public function __construct(DatePoint $from, DatePoint $to)
    {
        $this->from = DatePointParser::dateOf($from);
        $this->to = DatePointParser::dateOf($to);

        if ($this->from > $this->to) {
            throw new InvalidRatesImportPeriod('Дата начала периода не может быть позже даты окончания.');
        }
    }

    public function count(): int
    {
        return (int) $this->from->diff($this->to)->days + 1;
    }

    /** @return \DatePeriod<DatePoint, DatePoint, null> */
    public function dates(): \DatePeriod
    {
        return new \DatePeriod($this->from, new \DateInterval('P1D'), $this->to, \DatePeriod::INCLUDE_END_DATE);
    }
}
