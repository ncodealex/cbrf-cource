<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cources\Infrastructure\Cbr;

use App\Cources\Infrastructure\Cbr\CbrRateSetGateway;
use App\Cources\Infrastructure\Cbr\Exception\CbrHttpFailure;
use App\Cources\Infrastructure\Cbr\Exception\CbrInvalidResponse;
use App\Cources\Infrastructure\Cbr\Exception\CbrRateLimited;
use App\Cources\Infrastructure\Cbr\Exception\CbrRequestRateLimitExceeded;
use App\Cources\Infrastructure\Cbr\Exception\CbrSerialization;
use App\Cources\Infrastructure\Cbr\Exception\CbrUnavailable;
use App\Cources\Infrastructure\Cbr\Mapper\CbrRateSetResponseMapper;
use App\Cources\Infrastructure\Cbr\RetryAfterParser;
use App\Shared\Domain\DatePointParser;
use App\Support\RateLimiter\Application\RateLimitDecision;
use App\Support\RateLimiter\Application\RequestRateLimiter;
use App\Support\RateLimiter\Application\RequestRateLimiterFailure;
use App\Tests\Fixtures\Cbr\CbrFixtures;
use App\Tests\Fixtures\Cbr\CbrSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CbrRateSetGatewayTest extends TestCase
{
    /** @return iterable<string, array{string, class-string<\Throwable>}> */
    public static function invalidBodies(): iterable
    {
        yield 'пусто' => ['', CbrInvalidResponse::class];
        yield 'не XML' => ['Ошибка', CbrSerialization::class];
        yield 'обрезанный XML' => ['<ValCurs>', CbrSerialization::class];
        yield 'другой корень без данных' => ['<html/>', CbrSerialization::class];
        yield 'нет даты' => ['<ValCurs/>', CbrSerialization::class];
        yield 'пустой набор' => ['<ValCurs Date="24.10.2026"/>', CbrInvalidResponse::class];
        yield 'DTD' => ['<!DOCTYPE ValCurs [<!ENTITY x SYSTEM "file:///etc/passwd">]><ValCurs Date="24.10.2026">&x;</ValCurs>', CbrSerialization::class];
        yield 'некорректный курс из фикстуры' => [CbrFixtures::load('daily_invalid_value.xml'), CbrInvalidResponse::class];
        $quote = '<Valute ID="R01235"><CharCode>USD</CharCode><Nominal>1</Nominal><Name>Доллар США</Name><Value>81,4500</Value></Valute>';
        yield 'неверная дата' => ['<ValCurs Date="31.02.2026">'.$quote.'</ValCurs>', CbrInvalidResponse::class];
        yield 'дубли' => ['<ValCurs Date="24.10.2026">'.$quote.$quote.'</ValCurs>', CbrInvalidResponse::class];
        foreach ([
            '<CharCode>USD</CharCode>' => '<CharCode>RUB</CharCode>',
            '<Nominal>1</Nominal>' => '<Nominal>0</Nominal>',
            '<Value>81,4500</Value>' => '<Value>0,0000</Value>',
            '<Name>Доллар США</Name>' => '',
            'ID="R01235"' => 'ID=""',
        ] as $old => $new) {
            yield $old => ['<ValCurs Date="24.10.2026">'.str_replace($old, $new, $quote).'</ValCurs>', CbrInvalidResponse::class];
        }
        yield 'вложенный элемент' => [
            '<ValCurs Date="24.10.2026">'.str_replace(
                '<Value>81,4500</Value>',
                '<Value><Amount>81,4500</Amount></Value>',
                $quote
            ).'</ValCurs>',
            CbrSerialization::class,
        ];
        yield 'нет обязательного поля' => [
            '<ValCurs Date="24.10.2026">'.str_replace('<CharCode>USD</CharCode>', '', $quote).'</ValCurs>',
            CbrInvalidResponse::class,
        ];
        yield 'пустая котировка' => ['<ValCurs Date="24.10.2026"><Valute/></ValCurs>', CbrInvalidResponse::class];
        yield 'дубли полей' => [
            '<ValCurs Date="24.10.2026">'.str_replace(
                '<Nominal>1</Nominal>',
                '<Nominal>1</Nominal><Nominal>2</Nominal>',
                $quote
            ).'</ValCurs>',
            CbrSerialization::class,
        ];
        yield 'переполнение номинала' => [
            '<ValCurs Date="24.10.2026">'.str_replace(
                '<Nominal>1</Nominal>',
                '<Nominal>999999999999999999999999</Nominal>',
                $quote
            ).'</ValCurs>',
            CbrInvalidResponse::class,
        ];
    }

    /** @return iterable<string, array{int, class-string<\Throwable>}> */
    public static function statuses(): iterable
    {
        yield '429' => [429, CbrRateLimited::class];
        yield '500' => [500, CbrUnavailable::class];
        yield '503' => [503, CbrUnavailable::class];
        yield '404' => [404, CbrHttpFailure::class];
    }

    public function testFetchesRequestedDateAndPreservesQuotes(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://www.cbr.ru/scripts/XML_daily.asp?date_req=26/10/2026', $url);

            return new MockResponse(CbrFixtures::load('daily_2026-10-24.xml'));
        }, 'https://www.cbr.ru');
        $set = $this->createGateway($client)->fetch(DatePointParser::parse('2026-10-26'));
        self::assertSame('2026-10-24', $set->date());
        self::assertSame('Europe/Moscow', $set->effectiveDate->getTimezone()->getName());
        self::assertSame('81.4500', (string) $set->items['USD']->value);
        self::assertSame(1, $set->items['USD']->nominal);
        self::assertSame(100, $set->items['INR']->nominal);
        self::assertSame('96.8790', (string) $set->items['INR']->value);
        self::assertSame(4, $set->items['INR']->value->scale);
        self::assertSame('Индийских рупий', $set->items['INR']->name);
        self::assertSame('R01270', $set->items['INR']->sourceId);
        self::assertSame('356', $set->items['INR']->numCode);
        self::assertSame(10000, $set->items['IDR']->nominal);
    }

    private function createGateway(
        HttpClientInterface $client,
        ?RequestRateLimiter $rateLimiter = null,
        ?MockClock $clock = null,
    ): CbrRateSetGateway {
        $clock ??= new MockClock('2026-10-21 10:28:00 Europe/Moscow');
        if (null === $rateLimiter) {
            $rateLimiter = $this->createStub(RequestRateLimiter::class);
            $rateLimiter->method('acquire')->willReturn(new RateLimitDecision(true, DatePoint::createFromInterface($clock->now())));
        }

        return new CbrRateSetGateway(
            $client,
            new NullLogger(),
            CbrSerializer::create(),
            new CbrRateSetResponseMapper(),
            $rateLimiter,
            new RetryAfterParser($clock)
        );
    }

    public function testLatestHasNoDateAndMayBeEffectiveTomorrow(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::assertSame('https://www.cbr.ru/scripts/XML_daily.asp', $url);

            return new MockResponse('<?xml version="1.0" encoding="UTF-8"?><ValCurs Date="31.10.2026"><Valute ID="R01325"><NumCode>364</NumCode><CharCode>IRR</CharCode><Nominal>1000000</Nominal><Name>Иранских риалов</Name><Value>193,450000</Value><VunitRate>0,000193</VunitRate></Valute></ValCurs>');
        }, 'https://www.cbr.ru');
        $set = $this->createGateway($client)->fetchLatest();
        self::assertSame('2026-10-31', $set->date());
        self::assertSame(1000000, $set->items['IRR']->nominal);
        self::assertSame('193.450000', (string) $set->items['IRR']->value);
        self::assertSame(6, $set->items['IRR']->value->scale);
    }

    public function testIgnoresVunitRateAndAllowsMissingNumCode(): void
    {
        $set = $this->gateway(new MockResponse(CbrFixtures::load('daily_2026-10-23.xml')))->fetchLatest();
        self::assertSame('96.5000', (string) $set->items['INR']->value);
        $body = '<ValCurs Date="02/03/2001"><Valute ID="R01235"><CharCode>USD</CharCode><Nominal>1</Nominal><Name>Доллар США</Name><Value>28,6200</Value></Valute></ValCurs>';
        $set = $this->gateway(new MockResponse(CbrFixtures::windows1251($body)))->fetchLatest();
        self::assertSame('2001-03-02', $set->date());
        self::assertNull($set->items['USD']->numCode);
    }

    public function testRestoresLegacyCodesAndSkipsIncompleteRows(): void
    {
        $body = '<ValCurs Date="28.01.1994" name="Foreign Currency Market">'
            .'<Valute ID="R01235"><NumCode>840</NumCode><CharCode>USD</CharCode><Nominal>1</Nominal>'
            .'<Name>Доллар США</Name><Value>1542,0000</Value><VunitRate>1542</VunitRate></Valute>'
            .'<Valute ID="R01720A"><Nominal>10</Nominal><Name>Украинских карбованецев</Name>'
            .'<Value>0,4400</Value><VunitRate/></Valute>'
            .'<Valute ID="UNKNOWN"><Nominal>1</Nominal><Name>Запись без кода</Name>'
            .'<Value>1,0000</Value></Valute></ValCurs>';

        $set = $this->gateway(new MockResponse($body))->fetch(DatePointParser::parse('1994-01-31'));

        self::assertSame('1994-01-28', $set->date());
        self::assertCount(2, $set->items);
        self::assertSame('1542.0000', (string) $set->items['USD']->value);
        self::assertSame('R01720A', $set->items['UAK']->sourceId);
        self::assertSame('804', $set->items['UAK']->numCode);
        self::assertSame(10, $set->items['UAK']->nominal);
        self::assertSame('0.4400', (string) $set->items['UAK']->value);
    }

    private function gateway(MockResponse $response): CbrRateSetGateway
    {
        return $this->createGateway(new MockHttpClient($response, 'https://www.cbr.ru'));
    }

    #[DataProvider('invalidBodies')]
    public function testRejectsInvalidResponse(string $body, string $exception): void
    {
        $this->expectException($exception);
        $this->gateway(new MockResponse($body))->fetchLatest();
    }

    /** @param class-string<\Throwable> $exception */
    #[DataProvider('statuses')]
    public function testHttpError(int $status, string $exception): void
    {
        $this->expectException($exception);
        $this->gateway(new MockResponse('Ошибка', ['http_code' => $status]))->fetchLatest();
    }

    public function testLocalRateLimitSkipsHttpRequest(): void
    {
        $client = new MockHttpClient(new MockResponse(CbrFixtures::load('daily_2026-10-24.xml')));
        $retryAt = new DatePoint('2026-10-21 10:28:03 Europe/Moscow');
        $rateLimiter = $this->createMock(RequestRateLimiter::class);
        $rateLimiter->expects(self::once())->method('acquire')->willReturn(new RateLimitDecision(false, $retryAt));

        try {
            $this->createGateway($client, $rateLimiter)->fetchLatest();
            self::fail('Ожидалось локальное ограничение');
        } catch (CbrRequestRateLimitExceeded $e) {
            self::assertSame($retryAt, $e->retryAt());
            self::assertSame(0, $client->getRequestsCount());
        }
    }

    public function testRateLimiterFailureSkipsHttpRequest(): void
    {
        $client = new MockHttpClient(new MockResponse(CbrFixtures::load('daily_2026-10-24.xml')));
        $failure = new RequestRateLimiterFailure();
        $rateLimiter = $this->createMock(RequestRateLimiter::class);
        $rateLimiter->expects(self::once())->method('acquire')->willThrowException($failure);
        $this->expectExceptionObject($failure);

        try {
            $this->createGateway($client, $rateLimiter)->fetchLatest();
        } finally {
            self::assertSame(0, $client->getRequestsCount());
        }
    }

    public function testHttp429PreservesRetryAfterSeconds(): void
    {
        $clock = new MockClock('2026-10-21 10:28:00 Europe/Moscow');
        $response = new MockResponse('Ошибка', [
            'http_code' => 429,
            'response_headers' => ['Retry-After: 40'],
        ]);

        try {
            $this->createGateway(new MockHttpClient($response), clock: $clock)->fetchLatest();
            self::fail('Ожидался HTTP 429');
        } catch (CbrRateLimited $e) {
            self::assertSame(429, $e->httpStatus);
            self::assertSame('2026-10-21T10:28:40+03:00', $e->retryAt()?->format(\DATE_ATOM));
        }
    }

    public function testHttp503PreservesRetryAfterDate(): void
    {
        $response = new MockResponse('Ошибка', [
            'http_code' => 503,
            'response_headers' => ['Retry-After: Wed, 21 Oct 2026 07:28:40 GMT'],
        ]);

        try {
            $this->createGateway(new MockHttpClient($response))->fetchLatest();
            self::fail('Ожидался HTTP 503');
        } catch (CbrUnavailable $e) {
            self::assertSame(503, $e->httpStatus);
            self::assertSame('2026-10-21T07:28:40+00:00', $e->retryAt()?->format(\DATE_ATOM));
        }
    }

    public function testInvalidRetryAfterIsIgnored(): void
    {
        $response = new MockResponse('Ошибка', [
            'http_code' => 429,
            'response_headers' => ['Retry-After: неизвестно'],
        ]);

        try {
            $this->createGateway(new MockHttpClient($response))->fetchLatest();
            self::fail('Ожидался HTTP 429');
        } catch (CbrRateLimited $e) {
            self::assertNull($e->retryAt());
        }
    }

    public function testTransportError(): void
    {
        $this->expectException(CbrUnavailable::class);
        $this->gateway(new MockResponse('', ['error' => 'Сбой соединения']))->fetchLatest();
    }

    public function testTimeoutDuringReading(): void
    {
        $body = (static function (): \Generator {
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
