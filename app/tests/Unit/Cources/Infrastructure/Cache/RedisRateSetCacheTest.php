<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cources\Infrastructure\Cache;

use App\Cources\Application\Exception\RateSetCacheFailure;
use App\Cources\Application\Exception\RateSetNotFound;
use App\Cources\Domain\MoscowCalendar;
use App\Cources\Infrastructure\Cache\RedisRateSetCache;
use App\Cources\Infrastructure\Mapping\RateSetMapper;
use App\Tests\Unit\Cources\Domain\RateSets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedisRateSetCacheTest extends TestCase
{
    public function testMiss(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())->method('get')->with('rateset:date:2026-10-26')->willReturn(false);
        $this->expectException(RateSetNotFound::class);
        new RedisRateSetCache($redis, new RateSetMapper(), 60)->get(MoscowCalendar::date('2026-10-26'));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidPayloads(): iterable
    {
        yield 'JSON' => ['{'];
        yield 'структура' => ['{"items":[]}'];
        yield 'тип' => ['null'];
        yield 'пустой набор' => ['{"effectiveDate":"2026-10-24","items":[]}'];
    }

    #[DataProvider('invalidPayloads')]
    public function testCorruptedPayload(mixed $payload): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())->method('get')->willReturn($payload);
        $this->expectException(RateSetCacheFailure::class);
        new RedisRateSetCache($redis, new RateSetMapper(), 60)->get(MoscowCalendar::date('2026-10-26'));
    }

    public function testConnectionFailureIsNotMiss(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())->method('get')->willThrowException(new \RedisException('Нет соединения'));
        $this->expectException(RateSetCacheFailure::class);
        new RedisRateSetCache($redis, new RateSetMapper(), 60)->get(MoscowCalendar::date('2026-10-26'));
    }

    public function testWriteFailure(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())->method('set')->willReturn(false);
        $this->expectException(RateSetCacheFailure::class);
        new RedisRateSetCache($redis, new RateSetMapper(), 60)->put(MoscowCalendar::date('2026-10-26'), RateSets::saturday());
    }

    public function testWriteConnectionFailure(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())->method('set')->willThrowException(new \RedisException('Нет соединения'));
        $this->expectException(RateSetCacheFailure::class);
        new RedisRateSetCache($redis, new RateSetMapper(), 60)->put(MoscowCalendar::date('2026-10-26'), RateSets::saturday());
    }

    public function testRejectsNonPositiveTtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RedisRateSetCache($this->createStub(\Redis::class), new RateSetMapper(), 0);
    }
}
