<?php

declare(strict_types=1);

namespace App\Cources\Domain\Entity;

use App\Cources\Domain\Exception\InvalidRateItem;
use App\Cources\Domain\ExchangeRateCalculator;
use App\Cources\Domain\ValueObject\CurrencyCode;
use BcMath\Number;
use function sprintf;

/**
 * Официальная котировка: стоимость nominal единиц валюты в рублях.
 * sourceId — идентификатор валюты у источника (у ЦБ — R01235).
 * value хранится ровно с той точностью, с которой опубликован (scale включает хвостовые нули).
 */
final readonly class RateItem
{
    public function __construct(
        public CurrencyCode $code,
        public ?string $sourceId,
        public int $nominal,
        public Number $value,
        public string $name,
        public ?string $numCode = null,
    ) {
        if ('' === trim($name)) {
            throw new InvalidRateItem('Название валюты не может быть пустым');
        }
        if (!$code->isRuble() && (null === $sourceId || '' === trim($sourceId))) {
            throw new InvalidRateItem('Идентификатор валюты у источника не может быть пустым');
        }
        if ($nominal <= 0) {
            throw new InvalidRateItem(sprintf('Номинал валюты %s должен быть положительным, получено %d', $code,
                $nominal));
        }
        if ($value->compare(0) <= 0) {
            throw new InvalidRateItem(sprintf('Курс валюты %s должен быть положительным, получено %s', $code, $value));
        }
    }

    /** Условная котировка рубля для расчётов; Банк России не публикует её в наборе. */
    public static function ruble(): self
    {
        return new self(CurrencyCode::rub(), null, 1, new Number('1'), 'Российский рубль', '643');
    }

    /** Стоимость одной единицы в рублях: точно для номинала 10^k, иначе с INTERNAL_SCALE знаками. */
    public function unit(): Number
    {
        $scale = $this->sourceScale();

        return $this->value->div($this->nominal, $scale ?? ExchangeRateCalculator::INTERNAL_SCALE);
    }

    public function sourceScale(): ?int
    {
        $nominal = (string)$this->nominal;
        if (1 !== preg_match('/^10*$/', $nominal)) {
            return null;
        }

        return $this->value->scale + strlen($nominal) - 1;
    }
}
