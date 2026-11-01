<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cources\Domain;

use App\Cources\Domain\MoscowCalendar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoscowCalendarTest extends TestCase
{
    public function testDateIsMidnightInMoscow(): void
    {
        $date = MoscowCalendar::date('24.10.2026', 'd.m.Y');

        self::assertSame('2026-10-24 00:00:00 Europe/Moscow', $date->format('Y-m-d H:i:s e'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDates(): iterable
    {
        yield 'overflow' => ['2026-02-31'];
        yield 'wrong format' => ['24.10.2026'];
        yield 'garbage' => ['tomorrow'];
        yield 'trailing data' => ['2026-10-24T10:00'];
    }

    #[DataProvider('invalidDates')]
    public function testRejectsInvalidDate(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MoscowCalendar::date($value);
    }

    public function testDateOfUsesMoscowTimezone(): void
    {
        // 22:30 UTC 25.10 — уже 26.10 по Москве.
        $moment = new \DateTimeImmutable('2026-10-25 22:30:00', new \DateTimeZone('UTC'));

        self::assertSame('2026-10-26', MoscowCalendar::dateOf($moment)->format('Y-m-d'));
    }
}
