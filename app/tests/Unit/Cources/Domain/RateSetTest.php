<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cources\Domain;

use App\Cources\Domain\Exception\ExchangeRate\RateNotFoundForDate;
use App\Cources\Domain\ValueObject\CurrencyCode;
use PHPUnit\Framework\TestCase;

final class RateSetTest extends TestCase
{
    public function testQuotesAreIndexedAndSortedByCode(): void
    {
        $set = RateSets::saturday();

        self::assertSame(['CNY', 'EUR', 'IDR', 'INR', 'USD'], array_keys($set->items));
        self::assertSame('R01270', $set->get(CurrencyCode::fromString('inr'))->sourceId);
        self::assertSame('2026-10-24', $set->date());
        $this->expectException(RateNotFoundForDate::class);
        $set->get(CurrencyCode::fromString('GBP'));
    }

    public function testGetReturnsSyntheticRuble(): void
    {
        self::assertSame('Российский рубль', RateSets::saturday()->get(CurrencyCode::rub())->name);
        self::assertSame(1, RateSets::saturday()->get(CurrencyCode::rub())->nominal);
        self::assertNull(RateSets::saturday()->get(CurrencyCode::rub())->sourceId);
        self::assertSame('1', (string) RateSets::saturday()->get(CurrencyCode::rub())->unit());
    }

    public function testRejectsDuplicateQuote(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('повторяется валюта USD');

        RateSets::create('2026-10-24', [['USD', 'R01235', 1, '81.45'], ['usd', 'R01235', 1, '81.46']]);
    }

    public function testRejectsRubleQuote(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RateSets::create('2026-10-24', [['RUR', 'R00000', 1, '1']]);
    }

    public function testRejectsEmptySet(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RateSets::create('2026-10-24', []);
    }

    public function testContentHashIgnoresOrder(): void
    {
        $a = RateSets::create('2026-10-24', [['USD', 'R01235', 1, '81.4500'], ['EUR', 'R01239', 1, '94.1234']]);
        $b = RateSets::create('2026-10-24', [['EUR', 'R01239', 1, '94.1234'], ['USD', 'R01235', 1, '81.4500']]);

        self::assertSame($a->contentHash(), $b->contentHash());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a->contentHash());
    }

    public function testContentHashChangesWithStoredQuoteOrDate(): void
    {
        $base = RateSets::create('2026-10-24', [['USD', 'R01235', 1, '81.4500']])->contentHash();

        self::assertNotSame($base, RateSets::create('2026-10-24', [['USD', 'R01235', 1, '81.4501']])->contentHash());
        self::assertNotSame($base, RateSets::create('2026-10-24', [['USD', 'R01235', 1, '81.45']])->contentHash());
        self::assertNotSame($base, RateSets::create('2026-10-24', [['USD', 'R01235', 10, '81.4500']])->contentHash());
        self::assertNotSame($base, RateSets::create('2026-10-23', [['USD', 'R01235', 1, '81.4500']])->contentHash());
        self::assertNotSame($base, RateSets::create('2026-10-24', [['USD', 'R01235', 1, '81.4500', 'Доллар США']])->contentHash());
        self::assertNotSame($base, RateSets::create('2026-10-24', [['USD', 'R99999', 1, '81.4500']])->contentHash());
    }
}
