<?php

declare(strict_types=1);

namespace App\Tests\Unit\Courses\Domain;

use App\Courses\Domain\Entity\RateItem;
use App\Courses\Domain\Entity\RateSet;
use App\Courses\Domain\ValueObject\CurrencyCode;
use BcMath\Number;
use Symfony\Component\Clock\DatePoint;

/**
 * Условные наборы из спецификации (docs/exchange-rate-spec.md §4.5).
 */
final class RateSets
{
    /**
     * Пятница 23.10.2026 — предыдущий набор.
     */
    public static function friday(): RateSet
    {
        return self::create('2026-10-23', [
            ['USD', 'R01235', 1, '81.2000'],
            ['EUR', 'R01239', 1, '93.9900'],
            ['CNY', 'R01375', 1, '11.4000'],
            ['INR', 'R01270', 100, '96.5000'],
        ]);
    }

    /**
     * @param list<array{0: string, 1: string, 2: int, 3: numeric-string, 4?: string, 5?: string}> $quotes [code, sourceId, nominal, value, name?, numCode?]
     */
    public static function create(string $date, array $quotes): RateSet
    {
        return new RateSet(
            new DatePoint($date.' 00:00:00', new \DateTimeZone('Europe/Moscow')),
            array_map(
                static fn (array $q): RateItem => self::quote(...$q),
                $quotes,
            ),
        );
    }

    /**
     * @param numeric-string $value
     */
    public static function quote(
        string $code,
        string $sourceId,
        int $nominal,
        string $value,
        string $name = 'Валюта',
        ?string $numCode = null,
    ): RateItem {
        return new RateItem(CurrencyCode::fromString($code), $sourceId, $nominal, new Number($value), $name, $numCode);
    }

    /**
     * Суббота 24.10.2026 — текущий набор.
     */
    public static function saturday(): RateSet
    {
        return self::create('2026-10-24', [
            ['USD', 'R01235', 1, '81.4500', 'Доллар США', '840'],
            ['EUR', 'R01239', 1, '94.1234', 'Евро', '978'],
            ['CNY', 'R01375', 1, '11.4200', 'Китайский юань', '156'],
            ['INR', 'R01270', 100, '96.8790', 'Индийских рупий', '356'],
            ['IDR', 'R01280', 10000, '49.8765', 'Рупий', '360'],
        ]);
    }
}
