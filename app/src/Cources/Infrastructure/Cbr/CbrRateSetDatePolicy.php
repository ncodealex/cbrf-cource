<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Cbr;

use App\Cources\Application\Contract\ExternalRateSetGatewayDatePolicyInterface;
use App\Cources\Infrastructure\Cbr\Exception\CbrNotSupportRateSetDate;
use App\Shared\Domain\DateFormatInterface;
use App\Shared\Domain\DatePointParser;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ExternalRateSetGatewayDatePolicyInterface::class)]
final class CbrRateSetDatePolicy implements ExternalRateSetGatewayDatePolicyInterface
{
    public const string MIN_REQUEST_DATE = '1992-07-03';

    private readonly DatePoint $minRequestDate;

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
        $this->minRequestDate = DatePointParser::parse(self::MIN_REQUEST_DATE);
    }

    public function minRequestDate(): DatePoint
    {
        return $this->minRequestDate;
    }

    public function assertAllowed(DatePoint $date): void
    {
        $iso = DatePointParser::dateOf($date)->format(DateFormatInterface::ISO);
        $todayDate = DatePointParser::dateOf($this->clock->now());
        $today = $todayDate->format(DateFormatInterface::ISO);
        if ($iso < $this->minRequestDate->format(DateFormatInterface::ISO) || $iso > $today) {
            throw new CbrNotSupportRateSetDate(\sprintf('Центральный Банк РФ поддерживает котировки курсов в период с %s по текущую дату ( %s ) по Московскому времени. Запрошенная дата %s, часовой пояс: %s', $this->minRequestDate->format(DateFormatInterface::UI), $todayDate->format(DateFormatInterface::UI), $date->format(DateFormatInterface::UI), $date->getTimezone()->getName()));
        }
    }
}
