<?php

declare(strict_types=1);

namespace App\Tests\Integration\Courses;

use App\Courses\Application\Exception\RateSetCacheFailure;
use App\Courses\Application\Exception\RateSetNotFound;
use App\Courses\Infrastructure\Cache\RedisRateSetCache;
use App\Courses\Infrastructure\Mapping\RateSetMapper;
use App\Shared\Domain\DatePointParser;
use App\Tests\Unit\Courses\Domain\RateSets;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Exception\InvalidArgumentException as RedisConnectionFailure;

final class RedisRateSetCacheTest extends KernelTestCase
{
    public function testPutGetTtlAndExpiration(): void
    {
        $redis = self::getContainer()->get(\Redis::class);
        $date = DatePointParser::parse('2100-01-01')->modify('+'.random_int(0, 100000).' days');
        $key = 'rateset:date:'.$date->format('Y-m-d');
        $cache = new RedisRateSetCache($redis, new RateSetMapper(), 1);
        try {
            $cache->put($date, RateSets::saturday());
            self::assertSame(RateSets::saturday()->contentHash(), $cache->get($date)->contentHash());
            self::assertGreaterThan(0, $redis->pttl($key));
            self::assertLessThanOrEqual(1000, $redis->pttl($key));
            $payload = $redis->get($key);
            self::assertIsString($payload);
            self::assertStringContainsString('81.4500', $payload);
            usleep(1100000);
            $this->expectException(RateSetNotFound::class);
            $cache->get($date);
        } finally {
            $redis->del($key);
        }
    }

    public function testCorruptedPayload(): void
    {
        $redis = self::getContainer()->get(\Redis::class);
        $date = DatePointParser::parse('2400-01-01')->modify('+'.random_int(0, 100000).' days');
        $key = 'rateset:date:'.$date->format('Y-m-d');
        try {
            $redis->set($key, '{', ['ex' => 10]);
            $this->expectException(RateSetCacheFailure::class);
            new RedisRateSetCache($redis, new RateSetMapper(), 60)->get($date);
        } finally {
            $redis->del($key);
        }
    }

    public function testWrongRedisValueTypeIsFailure(): void
    {
        $redis = self::getContainer()->get(\Redis::class);
        $date = DatePointParser::parse('2500-01-01')->modify('+'.random_int(0, 100000).' days');
        $key = 'rateset:date:'.$date->format('Y-m-d');
        try {
            $redis->lPush($key, 'ошибочное значение');
            $redis->expire($key, 10);
            $this->expectException(RateSetCacheFailure::class);
            new RedisRateSetCache($redis, new RateSetMapper(), 60)->get($date);
        } finally {
            $redis->del($key);
        }
    }

    public function testUnavailableConnection(): void
    {
        $redis = new \Redis();
        $this->expectException(RateSetCacheFailure::class);
        new RedisRateSetCache($redis, new RateSetMapper(), 60)->get(DatePointParser::parse('2026-10-26'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function cacheOperations(): iterable
    {
        yield 'чтение' => ['get'];
        yield 'запись' => ['put'];
        yield 'удаление' => ['delete'];
    }

    #[DataProvider('cacheOperations')]
    public function testLazyConnectionFailure(string $operation): void
    {
        $redis = RedisAdapter::createConnection('redis://redis-unavailable.invalid:6379', [
            'lazy' => true,
            'timeout' => 0.1,
            'read_timeout' => 0.1,
        ]);
        self::assertInstanceOf(\Redis::class, $redis);
        $cache = new RedisRateSetCache($redis, new RateSetMapper(), 60);
        $date = DatePointParser::parse('2026-10-26');

        try {
            match ($operation) {
                'get' => $cache->get($date),
                'put' => $cache->put($date, RateSets::saturday()),
                'delete' => $cache->delete($date),
                default => throw new \LogicException('Неизвестная операция кеша'),
            };
            self::fail('Ожидался сбой ленивого подключения Redis');
        } catch (RateSetCacheFailure $e) {
            self::assertInstanceOf(RedisConnectionFailure::class, $e->getPrevious());
        }
    }
}
