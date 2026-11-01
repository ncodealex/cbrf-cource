<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cources\Domain;

use App\Cources\Domain\CurrencyPair;
use App\Cources\Domain\Exception\ExchangeRate\PreviousRateNotFound;
use App\Cources\Domain\Exception\ExchangeRate\RateNotFoundForDate;
use App\Cources\Domain\RateCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RateCalculatorTest extends TestCase
{
    private RateCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new RateCalculator();
    }

    /**
     * Неокруглённые значения; эталон посчитан bc -l со scale=30.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function rates(): iterable
    {
        yield 'USD/RUB — значение ЦБ как есть' => ['USD', 'RUB', '81.4500'];
        yield 'USD/RUR — RUR это рубль' => ['USD', 'RUR', '81.4500'];
        yield 'INR/RUB — номинал 100' => ['INR', 'RUB', '0.968790'];
        yield 'IDR/RUB — номинал 10 000' => ['IDR', 'RUB', '0.00498765'];
        yield 'INR/USD — кросс' => ['INR', 'USD', '0.011894290976058931860036832412'];
        yield 'EUR/CNY — кросс' => ['EUR', 'CNY', '8.241978984238178633975481611208'];
        yield 'RUB/USD — обратный' => ['RUB', 'USD', '0.012277470841006752608962553713'];
        yield 'USD/USD' => ['USD', 'USD', '1'];
        yield 'RUB/RUB' => ['RUB', 'RUR', '1'];
    }

    #[DataProvider('rates')]
    public function testRate(string $currency, string $base, string $expected): void
    {
        $rate = $this->calculator->rate(RateSets::saturday(), CurrencyPair::of($currency, $base));

        self::assertSame($expected, (string) $rate);
    }

    public function testCrossRateAccountsForNominalsOfBothCurrencies(): void
    {
        // 1 INR = 96.8790/100 RUB, 1 IDR = 49.8765/10000 RUB.
        $rate = $this->calculator->rate(RateSets::saturday(), CurrencyPair::of('INR', 'IDR'));

        self::assertSame('194.237767285194430242699467685182', (string) $rate);
    }

    public function testDiffIsComputedFromUnroundedRates(): void
    {
        $rate = $this->calculator->exchangeRate(RateSets::saturday(), RateSets::friday(), CurrencyPair::of('INR', 'USD'));

        self::assertSame('2026-10-24', $rate->effectiveDate->format('Y-m-d'));
        self::assertSame('2026-10-23', $rate->previousEffectiveDate->format('Y-m-d'));
        self::assertSame('0.011894290976058931860036832412', (string) $rate->rate);
        self::assertSame('0.011884236453201970443349753694', (string) $rate->previousRate);
        self::assertSame('0.000010054522856961416687078718', (string) $rate->diff);
    }

    public function testRoundingBeforeSubtractionWouldHideRealChange(): void
    {
        $rate = $this->calculator->exchangeRate(RateSets::saturday(), RateSets::friday(), CurrencyPair::of('INR', 'USD'));

        // Ошибочный подход: округлить оба курса до 4 знаков, затем вычесть.
        $naive = $rate->rate->round(4)->sub($rate->previousRate->round(4));

        self::assertSame('0.0000', (string) $naive);
        self::assertSame(1, $rate->diff->compare(0));
    }

    public function testDirectRateDiffIsExact(): void
    {
        $rate = $this->calculator->exchangeRate(RateSets::saturday(), RateSets::friday(), CurrencyPair::of('USD'));

        self::assertSame('81.4500', (string) $rate->rate);
        self::assertSame('81.2000', (string) $rate->previousRate);
        self::assertSame('0.2500', (string) $rate->diff);
    }

    public function testResultIncludesOfficialQuoteForUi(): void
    {
        $result = $this->calculator->exchangeRate(RateSets::saturday(), RateSets::friday(), CurrencyPair::of('INR', 'USD'));

        self::assertSame('INR', $result->currentRateItem->code->value);
        self::assertSame('R01270', $result->currentRateItem->sourceId);
        self::assertSame('Индийских рупий', $result->currentRateItem->name);
        self::assertSame(100, $result->currentRateItem->nominal);
        self::assertSame('96.8790', (string) $result->currentRateItem->value);
        self::assertSame('0.011894290976058931860036832412', (string) $result->rate);
    }

    public function testRubleHasSyntheticRateItem(): void
    {
        $result = $this->calculator->exchangeRate(RateSets::saturday(), RateSets::friday(), CurrencyPair::of('RUB', 'USD'));

        self::assertSame('RUB', $result->currentRateItem->code->value);
        self::assertSame('1', (string) $result->currentRateItem->value);
        self::assertSame('Российский рубль', $result->currentRateItem->name);
        self::assertSame('USD', $result->baseRateItem->code->value);
    }

    public function testRubleAgainstEuroUsesInverseRateAndEuroQuote(): void
    {
        $current = RateSets::saturday();
        $result = $this->calculator->exchangeRate($current, RateSets::friday(), CurrencyPair::of('RUB', 'EUR'));

        self::assertSame('RUB', $result->currentRateItem->code->value);
        self::assertSame('1', (string) $result->currentRateItem->value);
        self::assertSame('Российский рубль', $result->currentRateItem->name);
        self::assertSame('EUR', $result->baseRateItem->code->value);
        self::assertSame('0.010624350586570395884551556786', (string) $result->rate);
        self::assertSame(30, $result->rate->scale);
    }

    public function testNegativeDiff(): void
    {
        $rate = $this->calculator->exchangeRate(RateSets::saturday(), RateSets::friday(), CurrencyPair::of('EUR', 'CNY'));

        self::assertSame(-1, $rate->diff->compare(0));
        self::assertSame('-0.002757857867084523919255230897', (string) $rate->diff);
    }

    public function testDiffIsNotDerivedFromRoundedCrossRates(): void
    {
        $rate = $this->calculator->exchangeRate(RateSets::saturday(), RateSets::friday(), CurrencyPair::of('EUR', 'CNY'));

        self::assertSame('-0.002757857867084523919255230897', (string) $rate->diff);
        self::assertSame('-0.0027', (string) $rate->rate->round(4)->sub($rate->previousRate->round(4)));
    }

    public function testZeroDiff(): void
    {
        $friday = RateSets::create('2026-10-23', [['USD', 'R01235', 1, '81.4500']]);
        $rate = $this->calculator->exchangeRate(RateSets::saturday(), $friday, CurrencyPair::of('USD'));

        self::assertSame(0, $rate->diff->compare(0));
    }

    public function testMissingCurrencyInCurrentSet(): void
    {
        try {
            $this->calculator->exchangeRate(RateSets::saturday(), RateSets::friday(), CurrencyPair::of('GBP'));
            self::fail('Exception expected');
        } catch (RateNotFoundForDate $e) {
            self::assertSame('GBP', $e->currency);
            self::assertSame('2026-10-24', $e->effectiveDate);
        }
    }

    public function testMissingBaseCurrencyInCurrentSet(): void
    {
        $this->expectExceptionObject(new RateNotFoundForDate('GBP', '2026-10-24'));

        $this->calculator->rate(RateSets::saturday(), CurrencyPair::of('USD', 'GBP'));
    }

    public function testCurrencyMissingOnlyInPreviousSet(): void
    {
        // IDR есть в субботнем наборе, но отсутствует в пятничном.
        try {
            $this->calculator->exchangeRate(RateSets::saturday(), RateSets::friday(), CurrencyPair::of('USD', 'IDR'));
            self::fail('Exception expected');
        } catch (PreviousRateNotFound $e) {
            self::assertSame('IDR', $e->currency);
            self::assertSame('2026-10-23', $e->previousEffectiveDate);
        }
    }

    public function testPreviousSetMustPrecedeCurrent(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->calculator->exchangeRate(RateSets::friday(), RateSets::saturday(), CurrencyPair::of('USD'));
    }
}
