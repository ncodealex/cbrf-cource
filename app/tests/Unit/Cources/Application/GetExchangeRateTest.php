<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cources\Application;

use App\Cources\Application\Contract\RateSetProviderInterface;
use App\Cources\Application\Exception\RateSetNotFound;
use App\Cources\Application\UseCase\GetExchangeRate\GetExchangeRateHandler;
use App\Cources\Application\UseCase\GetExchangeRate\GetExchangeRateQuery;
use App\Cources\Application\UseCase\GetExchangeRate\GetExchangeRateResult;
use App\Cources\Domain\CurrencyPair;
use App\Cources\Domain\Entity\RateItem;
use App\Cources\Domain\ExchangeRate;
use App\Cources\Domain\ExchangeRateCalculator;
use App\Cources\Endpoints\Console\GetExchangeRate\GetExchangeRateCommand;
use App\Tests\Unit\Cources\Domain\RateSets;
use BcMath\Number;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class GetExchangeRateTest extends TestCase
{
    /** @return iterable<array{string, string, string}> */
    public static function pairs(): iterable
    {
        yield ['USD', 'RUB', '81.4500'];
        yield ['INR', 'RUB', '0.968790'];
        yield ['IDR', 'RUB', '0.00498765'];
        yield ['INR', 'USD', '0.011894'];
        yield ['RUB', 'USD', '0.0123'];
        yield ['RUB', 'RUB', '1'];
    }

    /** @return iterable<array{numeric-string, string}> */
    public static function changes(): iterable
    {
        yield ['82.0000', '-0.5500 RUB'];
        yield ['81.4500', '0.0000 RUB'];
        yield ['81.2000', '+0.2500 RUB'];
    }

    /** @return iterable<array{string, string, string, int}> */
    public static function inputs(): iterable
    {
        foreach (['2026-10-23', '23.10.2026', '23/10/2026', '23-10-2026', '1992-07-02', '2026-11-01'] as $date) {
            yield [$date, 'USD', 'json', 0];
        }
        yield ['2026-10-23', 'usd', 'table', 0];
        foreach (['1992-07-01', '2026-11-02', '2026-02-30', '2026-1-01', 'garbage'] as $date) {
            yield [$date, 'USD', 'json', 2];
        }
        yield ['2026-10-23', 'bad!', 'json', 2];
        yield ['2026-10-23', 'USD', 'xml', 2];
    }

    public function testHandlerResolvesPreviousFromEffectiveDate(): void
    {
        $provider = $this->createMock(RateSetProviderInterface::class);
        $dates    = [];
        $provider->expects(self::exactly(2))->method('resolve')->willReturnCallback(
            static function (DatePoint $date) use (&$dates) {
                $dates[] = $date->format('Y-m-d');

                return 1 === count($dates) ? RateSets::saturday() : RateSets::friday();
            }
        );
        $result = (new GetExchangeRateHandler($provider, new ExchangeRateCalculator()))(
            new GetExchangeRateQuery(new DatePoint('2026-10-26'), CurrencyPair::of('USD'))
        );
        self::assertSame(['2026-10-26', '2026-10-23'], $dates);
        self::assertSame('81.4500', (string)$result->rate);
        self::assertSame('0.2500', (string)$result->diff);
    }

    public function testProviderErrorPropagates(): void
    {
        $provider = $this->createStub(RateSetProviderInterface::class);
        $provider->method('resolve')->willThrowException(new RateSetNotFound('missing'));
        $this->expectException(RateSetNotFound::class);
        (new GetExchangeRateHandler($provider, new ExchangeRateCalculator()))(
            new GetExchangeRateQuery(new DatePoint('2026-10-26'), CurrencyPair::of('USD'))
        );
    }

    public function testDiffRoundsIndependently(): void
    {
        $item   = RateSets::quote('USD', 'usd', 1, '1.0000');
        $domain = new ExchangeRate(
            $item, RateItem::ruble(),
            new DatePoint('2026-10-24'), new Number('1.000049'),
            new DatePoint('2026-10-23'), new Number('0.999951'),
            new Number('0.000098'),
        );
        $result = GetExchangeRateResult::fromDomain(new DatePoint('2026-10-24'), CurrencyPair::of('USD'), $domain);
        self::assertSame('1.0000', (string)$result->rate);
        self::assertSame('1.0000', (string)$result->previousRate);
        self::assertSame('0.0001', (string)$result->diff);
    }

    #[DataProvider('pairs')]
    public function testResult(string $currency, string $base, string $expected): void
    {
        $pair          = CurrencyPair::of($currency, $base);
        $previous      = RateSets::create('2026-10-23', [
            ['USD', 'usd', 1, '82.0000'],
            ['INR', 'inr', 100, '96.5000'],
            ['IDR', 'idr', 10000, '50.0000'],
        ]);
        $domain        = (new ExchangeRateCalculator())->calculate(RateSets::saturday(), $previous, $pair);
        $before        = (string)$domain->rate;
        $requestedDate = new DatePoint('2026-10-26');
        $result        = GetExchangeRateResult::fromDomain($requestedDate, $pair, $domain);
        self::assertSame($expected, (string)$result->rate);
        self::assertSame((string)$domain->currentRateItem->value, (string)$result->sourceValue);
        self::assertSame($before, (string)$domain->rate);
        self::assertSame($requestedDate, $result->requestedDate);
        self::assertSame($domain->effectiveDate, $result->effectiveDate);
        self::assertSame($domain->previousEffectiveDate, $result->previousEffectiveDate);
        self::assertSame($pair->currency, $result->currency);
        self::assertSame($pair->base, $result->base);
        self::assertSame($domain->currentRateItem->value, $result->sourceValue);
        $scale = strlen(explode('.', $expected)[1] ?? '');
        foreach ([$result->rate, $result->previousRate, $result->diff] as $number) {
            self::assertSame($scale, $number->scale);
        }
    }

    /** @param numeric-string $previousValue */
    #[DataProvider('changes')]
    public function testConsoleChangeSign(string $previousValue, string $expected): void
    {
        $provider = $this->createStub(RateSetProviderInterface::class);
        $provider->method('resolve')->willReturnOnConsecutiveCalls(
            RateSets::saturday(),
            RateSets::create('2026-10-23', [['USD', 'usd', 1, $previousValue]]),
        );
        $endpoint = new GetExchangeRateCommand(
            new GetExchangeRateHandler($provider, new ExchangeRateCalculator()),
            new MockClock('2026-11-01 Europe/Moscow'),
        );
        $tester   = new CommandTester(new Command('app:rates:get', $endpoint));
        self::assertSame(Command::SUCCESS, $tester->execute(['currency' => 'USD']));
        self::assertStringContainsString($expected, $tester->getDisplay());
        self::assertStringNotContainsString('+0.0000', $tester->getDisplay());
    }

    #[DataProvider('inputs')]
    public function testConsole(string $date, string $currency, string $format, int $status): void
    {
        $provider = $this->createMock(RateSetProviderInterface::class);
        $provider->expects(0 === $status ? self::exactly(2) : self::never())
            ->method('resolve')->willReturnOnConsecutiveCalls(RateSets::saturday(), RateSets::friday());
        $endpoint = new GetExchangeRateCommand(
            new GetExchangeRateHandler($provider, new ExchangeRateCalculator()),
            new MockClock('2026-11-01 00:30:00 Europe/Moscow'),
        );
        $tester   = new CommandTester(new Command('app:rates:get', $endpoint));
        self::assertSame($status, $tester->execute([
            'currency' => $currency,
            '--date'   => $date,
            '--format' => $format,
        ], ['capture_stderr_separately' => true]));
        if (0 === $status && 'json' === $format) {
            /** @var array<string, string|int> $data */
            $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('81.4500', $data['rate']);
            self::assertSame((new DateTimeImmutable(str_replace('/', '.', $date)))->format('Y-m-d'),
                $data['requestedDate']);
            self::assertSame('2026-10-24', $data['effectiveDate']);
            self::assertSame('2026-10-23', $data['previousEffectiveDate']);
            self::assertSame('RUB', $data['base']);
            self::assertSame([
                'requestedDate'         => (new DateTimeImmutable(str_replace('/', '.', $date)))->format('Y-m-d'),
                'currency'              => 'USD',
                'base'                  => 'RUB',
                'currencyName'          => 'Доллар США',
                'nominal'               => 1,
                'sourceValue'           => '81.4500',
                'effectiveDate'         => '2026-10-24',
                'rate'                  => '81.4500',
                'previousEffectiveDate' => '2026-10-23',
                'previousRate'          => '81.2000',
                'diff'                  => '0.2500',
            ], $data);
            self::assertSame('', $tester->getErrorOutput());
        } elseif (0 === $status) {
            $output = $tester->getDisplay();
            foreach ([
                         'КУРС ВАЛЮТ ЦБ РФ',
                         'Валютная пара',
                         'Расчёт курса',
                         'USD / RUB',
                         'Доллар США',
                         '1 USD',
                         '81.4500 RUB / 1 USD',
                         '23.10.2026',
                         '24.10.2026',
                         '81.4500 RUB',
                         '81.2000 RUB',
                         '+0.2500 RUB',
                     ] as $expected) {
                self::assertStringContainsString($expected, $output);
            }
            self::assertStringNotContainsString('RUR', $output);
            self::assertSame('', $tester->getErrorOutput());
        } else {
            self::assertSame('', $tester->getDisplay());
            self::assertNotSame('', $tester->getErrorOutput());
        }
    }
}
