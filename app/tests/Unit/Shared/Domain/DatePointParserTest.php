<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\DatePointParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\DatePoint;

final class DatePointParserTest extends TestCase
{
    public function testTimezoneIsMoscow(): void
    {
        self::assertSame('Europe/Moscow', DatePointParser::TIMEZONE);
        self::assertSame('Europe/Moscow', DatePointParser::timezone()->getName());
    }

    #[DataProvider('moments')]
    public function testDateOfUsesMoscowDateWithoutChangingInput(string $value, string $expected): void
    {
        foreach ([\DateTime::class, \DateTimeImmutable::class] as $class) {
            $moment = new $class($value);
            $original = $moment->format('Y-m-d H:i:s.u e');

            $date = DatePointParser::dateOf($moment);

            self::assertInstanceOf(DatePoint::class, $date);
            self::assertSame($expected.' 00:00:00.000000 Europe/Moscow', $date->format('Y-m-d H:i:s.u e'));
            self::assertSame($original, $moment->format('Y-m-d H:i:s.u e'));
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function moments(): iterable
    {
        yield 'before midnight' => ['2026-10-25 20:59:59.999999 UTC', '2026-10-25'];
        yield 'midnight' => ['2026-10-25 21:00:00 UTC', '2026-10-26'];
        yield 'next day' => ['2026-10-25 22:30:00 UTC', '2026-10-26'];
        yield 'previous day' => ['2026-10-26 00:30:00 Asia/Tokyo', '2026-10-25'];
        yield 'Moscow midnight' => ['2026-10-26 00:00:00 Europe/Moscow', '2026-10-26'];
    }

    #[DataProvider('validDates')]
    public function testParsesDateAtMidnightInMoscow(string $value, string $expected): void
    {
        $date = DatePointParser::parse($value);

        self::assertInstanceOf(DatePoint::class, $date);
        self::assertSame($expected.' 00:00:00.000000 Europe/Moscow', $date->format('Y-m-d H:i:s.u e'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validDates(): iterable
    {
        yield 'ISO' => ['1992-07-03', '1992-07-03'];
        yield 'leap day' => ['29.02.1992', '1992-02-29'];
        yield 'double digit day and month' => ['31.12.1992', '1992-12-31'];

        foreach (['.', '/', '-'] as $separator) {
            foreach (['3', '03'] as $day) {
                foreach (['7', '07'] as $month) {
                    $value = $day.$separator.$month.$separator.'1992';
                    yield $value => [$value, '1992-07-03'];
                }
            }
        }
    }

    #[DataProvider('invalidDates')]
    public function testRejectsInvalidDate(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Некорректная календарная дата.');

        DatePointParser::parse($value);
    }

    /**
     * @return iterable<array{string}>
     */
    public static function invalidDates(): iterable
    {
        foreach ([
            '', 'tomorrow', '29.02.1993', '31.04.1992', '1992-02-31',
            '0.07.1992', '3.0.1992', '32.07.1992', '3.13.1992', '3.7.0000',
            '3.07/1992', '3/07-1992', ' 3.07.1992', '3.07.1992 ',
            "3.07.1992\n", "3.07.1992\0", '1992-07-03T00:00:00',
            '1992-7-03', '1992-07-3', '3.7.92', '003.07.1992',
        ] as $value) {
            yield [$value];
        }
    }
}
