<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cources;

use App\Cources\Application\Service\RateSetResolver;
use App\Cources\Domain\MoscowCalendar;
use App\Cources\Infrastructure\Cache\RedisRateSetCache;
use App\Cources\Infrastructure\Cbr\CbrRateSetGateway;
use App\Cources\Infrastructure\Cbr\Mapper\CbrRateSetResponseMapper;
use App\Cources\Infrastructure\Persistence\DoctrineRateSetRepository;
use App\Tests\Fixtures\Cbr\CbrFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RateSetResolverTest extends RateSetStorageTestCase
{
    /** @return iterable<string, array{bool}> */
    public static function redisAvailability(): iterable
    {
        yield 'Redis доступен' => [true];
        yield 'Redis недоступен' => [false];
    }

    public function testContainerWiresContracts(): void
    {
        $container  = self::getContainer();
        $resolver   = $container->get('test.rateset_resolver');
        $reflection = new \ReflectionClass($resolver);
        self::assertInstanceOf(DoctrineRateSetRepository::class,
            $reflection->getProperty('repository')->getValue($resolver));
        self::assertInstanceOf(RedisRateSetCache::class, $reflection->getProperty('cache')->getValue($resolver));
        self::assertInstanceOf(CbrRateSetGateway::class, $reflection->getProperty('gateway')->getValue($resolver));
    }

    #[DataProvider('redisAvailability')]
    public function testFullResolutionAndSubsequentHits(bool $available): void
    {
        $redis      = $available ? self::getContainer()->get(\Redis::class) : new \Redis();
        $date       = MoscowCalendar::date('2800-01-01')->modify('+' . random_int(0, 100000) . ' days');
        $key        = 'rateset:date:' . $date->format('Y-m-d');
        $client     = new MockHttpClient(new MockResponse(CbrFixtures::load('daily_2026-10-24.xml')),
            'https://www.cbr.ru');
        $repository = new DoctrineRateSetRepository($this->connection, $this->mapper);
        $resolver   = new RateSetResolver(
            new RedisRateSetCache($redis, $this->mapper, 60),
            $repository,
            new CbrRateSetGateway($client, new NullLogger(), self::getContainer()->get('serializer'),
                new CbrRateSetResponseMapper()),
            new NullLogger(),
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
}
