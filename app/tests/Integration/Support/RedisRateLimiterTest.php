<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Support\RateLimiter\Application\RequestRateLimiter;
use App\Support\RateLimiter\Infrastructure\RedisRateLimiterStorage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\RateLimiter\Policy\Rate;
use Symfony\Component\RateLimiter\Policy\TokenBucket;

final class RedisRateLimiterTest extends KernelTestCase
{
    private const string LIMITER_ID = 'cbr-cbr.global';

    public function testStorageRoundTripAndDelete(): void
    {
        $storage = self::getContainer()->get(RedisRateLimiterStorage::class);
        $id = 'integration-'.bin2hex(random_bytes(8));
        $state = new TokenBucket($id, 1, new Rate(new \DateInterval('PT1S'), 1));

        try {
            self::assertNull($storage->fetch($id));
            $storage->save($state);
            $loaded = $storage->fetch($id);
            self::assertInstanceOf(TokenBucket::class, $loaded);
            self::assertSame($id, $loaded->getId());
        } finally {
            $storage->delete($id);
        }

        self::assertNull($storage->fetch($id));
    }

    public function testCorruptedStateIsRejected(): void
    {
        $storage = self::getContainer()->get(RedisRateLimiterStorage::class);
        $redis = self::getContainer()->get(\Redis::class);
        $id = 'integration-'.bin2hex(random_bytes(8));
        $key = 'cbr:limiter:state:'.hash('sha256', $id);
        self::assertTrue($redis->set($key, 'повреждено'));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Повреждено состояние лимитера в Redis.');
            $storage->fetch($id);
        } finally {
            $redis->del($key);
        }
    }

    public function testConfiguredLimiterAllowsBurstOfTwoAndReturnsRetryTime(): void
    {
        $storage = self::getContainer()->get(RedisRateLimiterStorage::class);
        $storage->delete(self::LIMITER_ID);

        try {
            $first = self::getContainer()->get(RequestRateLimiter::class)->acquire();
            $second = self::getContainer()->get(RequestRateLimiter::class)->acquire();
            $third = self::getContainer()->get(RequestRateLimiter::class)->acquire();

            self::assertTrue($first->accepted);
            self::assertTrue($second->accepted);
            self::assertFalse($third->accepted);
            self::assertGreaterThan(new \DateTimeImmutable(), $third->retryAt);
            self::assertLessThanOrEqual(2.0, (float) $third->retryAt->format('U.u') - microtime(true));
            $remaining = (float) $third->retryAt->format('U.u') - microtime(true);
            if ($remaining > 0) {
                usleep((int) ceil(($remaining + 0.05) * 1_000_000));
            }
            $fourth = self::getContainer()->get(RequestRateLimiter::class)->acquire();
            self::assertTrue($fourth->accepted);
        } finally {
            $storage->delete(self::LIMITER_ID);
        }
    }

    public function testUnavailableRedisDoesNotBehaveLikeEmptyStorage(): void
    {
        $storage = new RedisRateLimiterStorage(new \Redis());

        $this->expectException(\RedisException::class);
        $storage->fetch('unavailable');
    }
}
