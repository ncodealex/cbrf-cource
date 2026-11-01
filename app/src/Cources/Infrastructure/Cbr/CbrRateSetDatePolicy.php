<?php
declare(strict_types=1);

namespace App\Cources\Infrastructure\Cbr;

use App\Cources\Application\Contract\ExternalRateSetGatewayDatePolicyInterface;
use App\Cources\Infrastructure\Cbr\Exception\CbrNotSupportRateSetDate;
use App\Shared\Domain\DateFormatInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\DatePoint;

final class CbrRateSetDatePolicy implements ExternalRateSetGatewayDatePolicyInterface
{
    public const string MIN_REQUEST_DATE = '1992-07-03';

    protected $minRequestDate;

    public function __construct(
        private ClockInterface $clock,
    ) {
        $this->minRequestDate = DatePoint::createFromFormat('Y-m-d', self::MIN_REQUEST_DATE);
    }

    public function assertAllowed(DatePoint $date): void
    {
        $iso   = $date->format(DateFormatInterface::ISO);
        $today = $this->clock->now()->format(DateFormatInterface::ISO);
        if ($iso < $this->minRequestDate->format(DateFormatInterface::ISO) || $iso > $today) {
            throw new CbrNotSupportRateSetDate(
                sprintf(
                    'Центральный Банк РФ поддерживает котировки курсов в период с %s по текущую дату ( %s ) по Московскому времени. Запрошенная дата %s, часовой пояс: %s',
                    $this->minRequestDate->format(DateFormatInterface::UI),
                    $this->clock->now()->format(DateFormatInterface::UI),
                    $date->format(DateFormatInterface::UI),
                    $date->getTimezone()->getName()
                )
            );
        }
    }
}
