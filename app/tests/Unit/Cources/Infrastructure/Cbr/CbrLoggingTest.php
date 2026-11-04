<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cources\Infrastructure\Cbr;

use App\Courses\Infrastructure\Cbr\CbrRateSetGateway;
use App\Courses\Infrastructure\Cbr\Exception\CbrGatewayException;
use App\Courses\Infrastructure\Cbr\Mapper\CbrRateSetResponseMapper;
use App\Courses\Infrastructure\Cbr\RetryAfterParser;
use App\Support\Logging\Infrastructure\RemoveXmlProcessor;
use App\Support\RateLimiter\Application\RateLimitDecision;
use App\Support\RateLimiter\Application\RequestRateLimiter;
use App\Tests\Fixtures\Cbr\CbrFixtures;
use App\Tests\Fixtures\Cbr\CbrSerializer;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use function array_key_exists;

final class CbrLoggingTest extends TestCase
{
    /**
     * @return iterable<array{string,int,bool}>
     */
    public static function cases(): iterable
    {
        foreach (['dev', 'prod'] as $environment) {
            foreach ([200, 429, 503] as $status) {
                yield [$environment, $status, 'dev' === $environment];
            }
        }
    }

    #[DataProvider('cases')]
    public function testRequestHasOneResultAndXmlIsolation(string $environment, int $status, bool $xml): void
    {
        $log     = new TestHandler();
        $logger  = new Logger('cbrf', [$log]);
        $clock   = new MockClock();
        $limiter = $this->createStub(RequestRateLimiter::class);
        $limiter->method('acquire')->willReturn(new RateLimitDecision(true, $clock->now()));
        $gateway = new CbrRateSetGateway(new MockHttpClient(new MockResponse(CbrFixtures::load('daily_2026-10-24.xml'),
            ['http_code' => $status])), new NullLogger(), CbrSerializer::create(), new CbrRateSetResponseMapper(),
            $limiter, new RetryAfterParser($clock), $logger, $environment);
        try {
            $gateway->fetchLatest();
        } catch (CbrGatewayException) {
        }
        $records = $log->getRecords();
        self::assertCount(2, $records);
        self::assertSame('cbr.request', $records[0]->message);
        self::assertSame(200 === $status ? 'cbr.response' : 'cbr.failure', $records[1]->message);
        self::assertSame($records[0]->context['request_id'], $records[1]->context['request_id']);
        self::assertSame($xml, array_key_exists('xml', $records[1]->context));
        self::assertArrayNotHasKey('credentials', $records[1]->context);
        if ($xml) {
            self::assertSame(base64_encode(CbrFixtures::load('daily_2026-10-24.xml')),
                $records[1]->context['xml_base64']);
        } else {
            self::assertArrayNotHasKey('xml_base64', $records[1]->context);
        }
        $processor = new RemoveXmlProcessor();
        self::assertArrayNotHasKey('xml', $processor($records[1])->context);
    }
}
