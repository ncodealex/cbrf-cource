<?php

declare(strict_types=1);

namespace App\Tests\Integration\Courses;

use App\Courses\Application\Contract\ExternalRateSetGatewayDatePolicyInterface;
use App\Courses\Application\Contract\ExternalRateSetGatewayInterface;
use App\Courses\Application\Contract\RateSetLoadLockInterface;
use App\Courses\Application\Exception\RateSetLoadLockFailure;
use App\Courses\Application\Service\RateSetResolver;
use App\Courses\Infrastructure\Cache\RedisRateSetCache;
use App\Courses\Infrastructure\Cbr\CbrRateSetGateway;
use App\Courses\Infrastructure\Cbr\Mapper\CbrRateSetResponseMapper;
use App\Courses\Infrastructure\Cbr\RetryAfterParser;
use App\Courses\Infrastructure\Lock\RedisRateSetLoadLock;
use App\Courses\Infrastructure\Persistence\DoctrineRateSetRepository;
use App\Shared\Domain\DatePointParser;
use App\Support\RateLimiter\Application\RateLimitDecision;
use App\Support\RateLimiter\Application\RequestRateLimiter;
use App\Tests\Fixtures\Cbr\CbrFixtures;
use App\Tests\Unit\Courses\Domain\RateSets;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\RedisStore;

final class RateSetResolverTest extends RateSetStorageTestCase
{
    /**
     * @return iterable<string, array{bool}>
     */
    public static function redisAvailability(): iterable
    {
        yield 'Redis доступен' => [true];
        yield 'Redis недоступен' => [false];
    }

    public function testContainerWiresContracts(): void
    {
        $container = self::getContainer();
        $resolver = $container->get('test.rateset_resolver');
        $reflection = new \ReflectionClass($resolver);
        self::assertInstanceOf(
            DoctrineRateSetRepository::class,
            $reflection->getProperty('repository')->getValue($resolver),
        );
        self::assertInstanceOf(RedisRateSetCache::class, $reflection->getProperty('cache')->getValue($resolver));
        self::assertInstanceOf(CbrRateSetGateway::class, $reflection->getProperty('gateway')->getValue($resolver));
        self::assertInstanceOf(
            RateSetLoadLockInterface::class,
            $reflection->getProperty('loadLock')->getValue($resolver),
        );
    }

    #[DataProvider('redisAvailability')]
    public function testFullResolutionAndSubsequentHits(bool $available): void
    {
        $redis = $available ? self::getContainer()->get(\Redis::class) : new \Redis();
        $date = DatePointParser::parse('2100-01-01')->modify('+'.random_int(0, 100000).' days');
        $key = 'rateset:date:'.$date->format('Y-m-d');
        $client = new MockHttpClient(
            new MockResponse(CbrFixtures::load('daily_2026-10-24.xml')),
            'https://www.cbr.ru',
        );
        $repository = new DoctrineRateSetRepository($this->connection, $this->mapper);
        $rateLimiter = $this->createStub(RequestRateLimiter::class);
        $rateLimiter->method('acquire')->willReturn(new RateLimitDecision(true, new DatePoint()));
        $datePolicy = $this->createStub(ExternalRateSetGatewayDatePolicyInterface::class);
        $resolver = new RateSetResolver(
            new RedisRateSetCache($redis, $this->mapper, 60),
            $repository,
            new CbrRateSetGateway(
                $client,
                new NullLogger(),
                self::getContainer()->get('serializer'),
                new CbrRateSetResponseMapper(),
                $rateLimiter,
                new RetryAfterParser(self::getContainer()->get(ClockInterface::class)),
            ),
            $datePolicy,
            new NullLogger(),
            self::getContainer()->get(RateSetLoadLockInterface::class),
        );
        try {
            $set = $resolver->resolve($date);
            self::assertSame('2026-10-24', $set->date());
            self::assertSame($set->contentHash(), $repository->get($date)->contentHash());
            self::assertSame($set->contentHash(), $resolver->resolve($date)->contentHash());
            if ($available) {
                $redis->del($key);
                self::assertSame($set->contentHash(), $resolver->resolve($date)->contentHash());
                self::assertGreaterThan(0, $redis->ttl($key));
            }
            self::assertSame(1, $client->getRequestsCount());
        } finally {
            if ($available) {
                $redis->del($key);
            }
        }
    }

    public function testUnavailableRedisFallsBackToPostgresWithoutLockOrGateway(): void
    {
        $date = DatePointParser::parse('2026-10-23');
        $set = RateSets::saturday();
        $repository = new DoctrineRateSetRepository($this->connection, $this->mapper);
        $repository->save($date, $set);
        $gateway = $this->createMock(ExternalRateSetGatewayInterface::class);
        $gateway->expects(self::never())->method('fetch');
        $loadLock = $this->createMock(RateSetLoadLockInterface::class);
        $loadLock->expects(self::never())->method('synchronized');
        $datePolicy = $this->createStub(ExternalRateSetGatewayDatePolicyInterface::class);
        $resolver = new RateSetResolver(
            new RedisRateSetCache($this->unavailableRedis(), $this->mapper, 60),
            $repository,
            $gateway,
            $datePolicy,
            new NullLogger(),
            $loadLock,
        );

        self::assertSame($set->contentHash(), $resolver->resolve($date)->contentHash());
    }

    public function testUnavailableRedisAndMissingPostgresDataFailsBeforeRateLimiterAndHttp(): void
    {
        $redis = $this->unavailableRedis();
        $client = new MockHttpClient(
            new MockResponse(CbrFixtures::load('daily_2026-10-24.xml')),
            'https://www.cbr.ru',
        );
        $rateLimiter = $this->createMock(RequestRateLimiter::class);
        $rateLimiter->expects(self::never())->method('acquire');
        $datePolicy = $this->createStub(ExternalRateSetGatewayDatePolicyInterface::class);
        $resolver = new RateSetResolver(
            new RedisRateSetCache($redis, $this->mapper, 60),
            new DoctrineRateSetRepository($this->connection, $this->mapper),
            new CbrRateSetGateway(
                $client,
                new NullLogger(),
                self::getContainer()->get('serializer'),
                new CbrRateSetResponseMapper(),
                $rateLimiter,
                new RetryAfterParser(self::getContainer()->get(ClockInterface::class)),
            ),
            $datePolicy,
            new NullLogger(),
            new RedisRateSetLoadLock(
                new LockFactory(new RedisStore($redis)),
                new NullLogger(),
                30.0,
            ),
        );

        try {
            $resolver->resolve(DatePointParser::parse('2026-10-23'));
            self::fail('Ожидался контролируемый сбой распределённой блокировки');
        } catch (RateSetLoadLockFailure $e) {
            self::assertNotNull($e->getPrevious());
            self::assertSame(0, $client->getRequestsCount());
        }
    }

    private function unavailableRedis(): \Redis
    {
        $redis = RedisAdapter::createConnection('redis://redis-unavailable.invalid:6379', [
            'lazy' => true,
            'timeout' => 0.1,
            'read_timeout' => 0.1,
        ]);
        self::assertInstanceOf(\Redis::class, $redis);

        return $redis;
    }
}
