<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cources\Domain;

use App\Cources\Domain\CurrencyPair;
use App\Cources\Domain\Exception\InvalidCurrencyCode;
use App\Cources\Domain\ValueObject\CurrencyCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurrencyCodeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validCodes(): iterable
    {
        yield 'upper case' => ['USD', 'USD'];
        yield 'lower case' => ['usd', 'USD'];
        yield 'mixed case with spaces' => [' uSd ', 'USD'];
        yield 'RUR is ruble' => ['RUR', 'RUB'];
        yield 'rur lower case' => ['rur', 'RUB'];
        yield 'RUB' => ['rub', 'RUB'];
    }

    #[DataProvider('validCodes')]
    public function testNormalizesInput(string $input, string $expected): void
    {
        self::assertSame($expected, CurrencyCode::fromString($input)->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCodes(): iterable
    {
        yield 'too short' => ['US'];
        yield 'too long' => ['USDT'];
        yield 'digit' => ['US1'];
        yield 'cyrillic' => ['доллар'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidCodes')]
    public function testRejectsMalformedCode(string $input): void
    {
        $this->expectException(InvalidCurrencyCode::class);

        CurrencyCode::fromString($input);
    }

    public function testRubAndRurAreEqual(): void
    {
        self::assertTrue(CurrencyCode::fromString('RUR')->equals(CurrencyCode::rub()));
        self::assertTrue(CurrencyCode::fromString('rur')->isRuble());
    }

    public function testPairDefaultsToRubleBase(): void
    {
        $pair = new CurrencyPair(CurrencyCode::fromString('usd'), CurrencyCode::rub());

        self::assertSame('USD/RUB', (string) $pair);
        self::assertTrue($pair->isExact());
    }

    public function testCrossPairIsNotExact(): void
    {
        self::assertFalse($this->pair('INR', 'USD')->isExact());
        self::assertFalse($this->pair('RUB', 'USD')->isExact());
        self::assertTrue($this->pair('EUR', 'eur')->isExact());
    }

    private function pair(string $currency, string $base): CurrencyPair
    {
        return new CurrencyPair(CurrencyCode::fromString($currency), CurrencyCode::fromString($base));
    }
}
