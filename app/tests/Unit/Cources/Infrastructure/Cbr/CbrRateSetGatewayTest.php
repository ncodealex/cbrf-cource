<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cources\Infrastructure\Cbr;

use App\Shared\Domain\DatePointParser;
use App\Cources\Infrastructure\Cbr\CbrRateSetGateway;
use App\Cources\Infrastructure\Cbr\Exception\CbrInvalidResponse;
use App\Cources\Infrastructure\Cbr\Exception\CbrRateLimited;
use App\Cources\Infrastructure\Cbr\Exception\CbrUnavailable;
use App\Cources\Infrastructure\Cbr\Mapper\CbrRateSetResponseMapper;
use App\Tests\Fixtures\Cbr\CbrFixtures;
use App\Tests\Fixtures\Cbr\CbrSerializer;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class CbrRateSetGatewayTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function invalidBodies(): iterable
    {
        yield 'пусто' => [''];
        yield 'не XML' => ['Ошибка'];
        yield 'обрезанный XML' => ['<ValCurs>'];
        yield 'другой корень' => ['<html/>'];
        yield 'нет даты' => ['<ValCurs/>'];
        yield 'пустой набор' => ['<ValCurs Date="24.10.2026"/>'];
        yield 'DTD' => ['<!DOCTYPE ValCurs [<!ENTITY x SYSTEM "file:///etc/passwd">]><ValCurs Date="24.10.2026">&x;</ValCurs>'];
        yield 'некорректный курс из фикстуры' => [CbrFixtures::load('daily_invalid_value.xml')];
        $quote = '<Valute ID="R01235"><CharCode>USD</CharCode><Nominal>1</Nominal><Name>Доллар США</Name><Value>81,4500</Value></Valute>';
        yield 'неверная дата' => ['<ValCurs Date="31.02.2026">' . $quote . '</ValCurs>'];
        yield 'дубли' => ['<ValCurs Date="24.10.2026">' . $quote . $quote . '</ValCurs>'];
        foreach ([
                     '<CharCode>USD</CharCode>' => '<CharCode>RUB</CharCode>',
                     '<Nominal>1</Nominal>'     => '<Nominal>0</Nominal>',
                     '<Value>81,4500</Value>'   => '<Value>0,0000</Value>',
                     '<Name>Доллар США</Name>'  => '',
                     'ID="R01235"'              => 'ID=""',
                 ] as $old => $new) {
            yield $old => ['<ValCurs Date="24.10.2026">' . str_replace($old, $new, $quote) . '</ValCurs>'];
        }
        yield 'другой корень с котировками' => ['<WrongRoot Date="24.10.2026">' . $quote . '</WrongRoot>'];
        yield 'вложенный элемент' => [
            '<ValCurs Date="24.10.2026">' . str_replace('<Value>81,4500</Value>',
                '<Value><Amount>81,4500</Amount></Value>', $quote) . '</ValCurs>',
        ];
        yield 'нет обязательного поля' => [
            '<ValCurs Date="24.10.2026">' . str_replace('<CharCode>USD</CharCode>', '', $quote) . '</ValCurs>',
        ];
        yield 'пустая котировка' => ['<ValCurs Date="24.10.2026"><Valute/></ValCurs>'];
        yield 'дубли полей' => [
            '<ValCurs Date="24.10.2026">' . str_replace('<Nominal>1</Nominal>',
                '<Nominal>1</Nominal><Nominal>2</Nominal>', $quote) . '</ValCurs>',
        ];
        yield 'переполнение номинала' => [
            '<ValCurs Date="24.10.2026">' . str_replace('<Nominal>1</Nominal>',
                '<Nominal>999999999999999999999999</Nominal>', $quote) . '</ValCurs>',
        ];
    }

    /** @return iterable<string, array{int, class-string<Throwable>}> */
    public static function statuses(): iterable
    {
        yield '429' => [429, CbrRateLimited::class];
        yield '500' => [500, CbrUnavailable::class];
        yield '503' => [503, CbrUnavailable::class];
        yield '404' => [404, CbrUnavailable::class];
    }

    public function testFetchesRequestedDateAndPreservesQuotes(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://www.cbr.ru/scripts/XML_daily.asp?date_req=26/10/2026', $url);

            return new MockResponse(CbrFixtures::load('daily_2026-10-24.xml'));
        }, 'https://www.cbr.ru');
        $set    = $this->createGateway($client)->fetch(DatePointParser::parse('2026-10-26'));
        self::assertSame('2026-10-24', $set->date());
        self::assertSame('Europe/Moscow', $set->effectiveDate->getTimezone()->getName());
        self::assertSame('81.4500', (string)$set->items['USD']->value);
        self::assertSame(1, $set->items['USD']->nominal);
        self::assertSame(100, $set->items['INR']->nominal);
        self::assertSame('96.8790', (string)$set->items['INR']->value);
        self::assertSame(4, $set->items['INR']->value->scale);
        self::assertSame('Индийских рупий', $set->items['INR']->name);
        self::assertSame('R01270', $set->items['INR']->sourceId);
        self::assertSame('356', $set->items['INR']->numCode);
        self::assertSame(10000, $set->items['IDR']->nominal);
    }

    private function createGateway(HttpClientInterface $client): CbrRateSetGateway
    {
        return new CbrRateSetGateway($client, new NullLogger(), CbrSerializer::create(),
            new CbrRateSetResponseMapper());
    }

    public function testLatestHasNoDateAndMayBeEffectiveTomorrow(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::assertSame('https://www.cbr.ru/scripts/XML_daily.asp', $url);

            return new MockResponse('<?xml version="1.0" encoding="UTF-8"?><ValCurs Date="31.10.2026"><Valute ID="R01325"><NumCode>364</NumCode><CharCode>IRR</CharCode><Nominal>1000000</Nominal><Name>Иранских риалов</Name><Value>193,450000</Value><VunitRate>0,000193</VunitRate></Valute></ValCurs>');
        }, 'https://www.cbr.ru');
        $set    = $this->createGateway($client)->fetchLatest();
        self::assertSame('2026-10-31', $set->date());
        self::assertSame(1000000, $set->items['IRR']->nominal);
        self::assertSame('193.450000', (string)$set->items['IRR']->value);
        self::assertSame(6, $set->items['IRR']->value->scale);
    }

    public function testIgnoresVunitRateAndAllowsMissingNumCode(): void
    {
        $set = $this->gateway(new MockResponse(CbrFixtures::load('daily_2026-10-23.xml')))->fetchLatest();
        self::assertSame('96.5000', (string)$set->items['INR']->value);
        $body = '<ValCurs Date="02/03/2001"><Valute ID="R01235"><CharCode>USD</CharCode><Nominal>1</Nominal><Name>Доллар США</Name><Value>28,6200</Value></Valute></ValCurs>';
        $set  = $this->gateway(new MockResponse(CbrFixtures::windows1251($body)))->fetchLatest();
        self::assertSame('2001-03-02', $set->date());
        self::assertNull($set->items['USD']->numCode);
    }

    private function gateway(MockResponse $response): CbrRateSetGateway
    {
        return $this->createGateway(new MockHttpClient($response, 'https://www.cbr.ru'));
    }

    #[DataProvider('invalidBodies')]
    public function testRejectsInvalidResponse(string $body): void
    {
        $this->expectException(CbrInvalidResponse::class);
        $this->gateway(new MockResponse($body))->fetchLatest();
    }

    /** @param class-string<Throwable> $exception */
    #[DataProvider('statuses')]
    public function testHttpError(int $status, string $exception): void
    {
        $this->expectException($exception);
        $this->gateway(new MockResponse('Ошибка', ['http_code' => $status]))->fetchLatest();
    }

    public function testTransportError(): void
    {
        $this->expectException(CbrUnavailable::class);
        $this->gateway(new MockResponse('', ['error' => 'Сбой соединения']))->fetchLatest();
    }

    public function testTimeoutDuringReading(): void
    {
        $body = (static function (): Generator {
            yield '';
        })();
        try {
            $this->gateway(new MockResponse($body))->fetchLatest();
            self::fail('Ожидалась ошибка таймаута');
        } catch (CbrUnavailable $e) {
            self::assertInstanceOf(TimeoutException::class, $e->getPrevious());
        }
    }

    public function testTransportErrorDuringRequest(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new TransportException('Сбой соединения');
        });
        $this->expectException(CbrUnavailable::class);
        $this->createGateway($client)->fetchLatest();
    }
}
