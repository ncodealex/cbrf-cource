<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cources;

use App\Cources\Application\Exception\RateSetCacheFailure;
use App\Cources\Application\Exception\RateSetNotFound;
use App\Cources\Domain\MoscowCalendar;
use App\Cources\Infrastructure\Cache\RedisRateSetCache;
use App\Cources\Infrastructure\Mapping\RateSetMapper;
use App\Tests\Unit\Cources\Domain\RateSets;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RedisRateSetCacheTest extends KernelTestCase
{
    public function testPutGetTtlAndExpiration(): void
    {
        $redis = self::getContainer()->get(\Redis::class);
        $date = MoscowCalendar::date('2100-01-01')->modify('+'.random_int(0, 100000).' days');
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
        $date = MoscowCalendar::date('2400-01-01')->modify('+'.random_int(0, 100000).' days');
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
        $date = MoscowCalendar::date('2500-01-01')->modify('+'.random_int(0, 100000).' days');
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
        new RedisRateSetCache($redis, new RateSetMapper(), 60)->get(MoscowCalendar::date('2026-10-26'));
    }
}
