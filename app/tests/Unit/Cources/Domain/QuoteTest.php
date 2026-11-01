<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cources\Domain;

use App\Cources\Domain\Entity\RateItem;
use App\Cources\Domain\ValueObject\CurrencyCode;
use BcMath\Number;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuoteTest extends TestCase
{
    /**
     * @return iterable<string, array{int, numeric-string, string}>
     */
    public static function unitRates(): iterable
    {
        yield 'USD, nominal 1' => [1, '81.4500', '81.4500'];
        yield 'INR, nominal 100' => [100, '96.8790', '0.968790'];
        yield 'IDR, nominal 10 000' => [10000, '49.8765', '0.00498765'];
        yield 'IRR, nominal 1 000 000' => [1000000, '19.6418', '0.0000196418'];
        yield 'trailing zeros kept' => [10, '12.3000', '1.23000'];
    }

    /**
     * @param numeric-string $value
     */
    #[DataProvider('unitRates')]
    public function testUnitRateIsExactForPowerOfTenNominal(int $nominal, string $value, string $expected): void
    {
        self::assertSame($expected, (string) RateSets::quote('XXX', 'R00000', $nominal, $value)->unit());
    }

    public function testUnitRateForNonPowerOfTenNominalUsesInternalScale(): void
    {
        $rate = RateSets::quote('XXX', 'R00000', 3, '10.0000')->unit();

        self::assertSame('3.333333333333333333333333333333', (string) $rate);
    }

    public function testValueKeepsSourceScale(): void
    {
        self::assertSame(4, RateSets::quote('USD', 'R01235', 1, '81.4500')->value->scale);
    }

    /**
     * @return iterable<string, array{string, int, numeric-string}>
     */
    public static function invalidQuotes(): iterable
    {
        yield 'zero nominal' => ['R01235', 0, '1.0'];
        yield 'negative nominal' => ['R01235', -1, '1.0'];
        yield 'zero value' => ['R01235', 1, '0.0000'];
        yield 'negative value' => ['R01235', 1, '-1.5'];
        yield 'empty id' => [' ', 1, '1.5'];
    }

    /**
     * @param numeric-string $value
     */
    #[DataProvider('invalidQuotes')]
    public function testRejectsInvalidQuote(string $sourceId, int $nominal, string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RateItem(CurrencyCode::fromString('USD'), $sourceId, $nominal, new Number($value), 'Доллар США');
    }
}
