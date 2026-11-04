<?php

declare(strict_types=1);

namespace App\Courses\Domain\Entity;

use App\Courses\Domain\Exception\ExchangeRate\RateNotFoundForDate;
use App\Courses\Domain\Exception\InvalidRateSet;
use App\Courses\Domain\ValueObject\CurrencyCode;
use App\Shared\Domain\DateFormatInterface;
use Symfony\Component\Clock\DatePoint;
use function sprintf;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;
use const SORT_STRING;

/**
 * Официальный набор курсов на дату действия. Рубль в набор не входит: его курс к себе — 1.
 */
final readonly class RateSet
{
    /**
     * @var array<string, RateItem> котировки по буквенному коду, отсортированы
     */
    public array $items;

    /**
     * @param iterable<mixed> $rateItems
     */
    public function __construct(
        public DatePoint $effectiveDate,
        iterable $rateItems,
    ) {
        $byCode = [];
        foreach ($rateItems as $rateItem) {
            if (!$rateItem instanceof RateItem) {
                throw new InvalidRateSet('Элемент не реализует RateItem');
            }
            $code = $rateItem->code->value;
            if ($rateItem->code->isRuble()) {
                throw new InvalidRateSet('Набор курсов не должен содержать котировку рубля');
            }
            if (isset($byCode[$code])) {
                throw new InvalidRateSet(sprintf('В наборе курсов от %s повторяется валюта %s', $this->date(), $code));
            }
            $byCode[$code] = $rateItem;
        }
        if ([] === $byCode) {
            throw new InvalidRateSet(sprintf('Набор курсов от %s не содержит котировок', $this->date()));
        }
        ksort($byCode, SORT_STRING);
        $this->items = $byCode;
    }

    public function date(): string
    {
        return $this->effectiveDate->format(DateFormatInterface::ISO);
    }

    /**
     * @throws RateNotFoundForDate
     */
    public function get(CurrencyCode $code): RateItem
    {
        if ($code->isRuble()) {
            return RateItem::ruble();
        }
        if (isset($this->items[$code->value])) {
            return $this->items[$code->value];
        }
        throw new RateNotFoundForDate($code->value, $this->date());
    }

    /**
     * SHA-256 канонического представления котировок. Не зависит от порядка валют;
     * изменение сохранённых реквизитов котировки, включая название, меняет хэш.
     */
    public function contentHash(): string
    {
        $items = [];
        foreach ($this->items as $code => $item) {
            $items[] = [
                $code,
                $item->sourceId,
                $item->numCode,
                $item->name,
                $item->nominal,
                (string)$item->value,
                $item->value->scale,
            ];
        }

        return hash('sha256', json_encode([$this->date(), $items], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
