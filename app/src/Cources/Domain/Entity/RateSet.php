<?php

declare(strict_types=1);

namespace App\Cources\Domain\Entity;

use App\Cources\Domain\Exception\ExchangeRate\RateNotFoundForDate;
use App\Cources\Domain\Exception\InvalidRateSet;
use App\Cources\Domain\ValueObject\CurrencyCode;
use Symfony\Component\Clock\DatePoint;

/**
 * Официальный набор курсов на дату действия. Рубль в набор не входит: его курс к себе — 1.
 */
final readonly class RateSet
{
    /** @var array<string, RateItem> котировки по буквенному коду, отсортированы */
    public array $items;

    /**
     * @param DatePoint          $effectiveDate
     * @param iterable<RateItem> $rateItems
     */
    public function __construct(
        public DatePoint $effectiveDate,
        iterable $rateItems
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
                throw new InvalidRateSet(\sprintf('В наборе курсов от %s повторяется валюта %s', $this->date(), $code));
            }
            $byCode[$code] = $rateItem;
        }
        if ([] === $byCode) {
            throw new InvalidRateSet(\sprintf('Набор курсов от %s не содержит котировок', $this->date()));
        }
        ksort($byCode, \SORT_STRING);
        $this->items = $byCode;
    }

    /**
     * @return string
     */
    public function date(): string
    {
        return $this->effectiveDate->format('Y-m-d');
    }


    /**
     * @param CurrencyCode $code
     *
     * @return RateItem
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
        throw new RateNotFoundForDate(
            $code->value,
            $this->date()
        );
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
