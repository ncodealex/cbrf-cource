<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cources\Infrastructure\Lock;

use App\Cources\Application\Exception\RateSetLoadLockFailure;
use App\Cources\Infrastructure\Lock\RedisRateSetLoadLockGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockInterface;

final class RedisRateSetLoadLockGuardTest extends TestCase
{
    public function testRefreshesOwnedLock(): void
    {
        $lock = $this->createMock(LockInterface::class);
        $lock->expects(self::once())->method('isExpired')->willReturn(false);
        $lock->expects(self::once())->method('isAcquired')->willReturn(true);
        $lock->expects(self::once())->method('refresh')->with(30.0);

        new RedisRateSetLoadLockGuard($lock, 30.0, new NullLogger(), [])->refresh();
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function lostOwnership(): iterable
    {
        yield 'истёк TTL' => [true, true];
        yield 'ресурс больше не принадлежит владельцу' => [false, false];
    }

    #[DataProvider('lostOwnership')]
    public function testLostOwnershipIsRetryableFailure(bool $expired, bool $acquired): void
    {
        $lock = $this->createMock(LockInterface::class);
        $lock->method('isExpired')->willReturn($expired);
        $lock->method('isAcquired')->willReturn($acquired);
        $lock->expects(self::never())->method('refresh');

        try {
            new RedisRateSetLoadLockGuard($lock, 30.0, new NullLogger(), [])->refresh();
            self::fail('Ожидалась потеря владения');
        } catch (RateSetLoadLockFailure $e) {
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            self::assertNull($e->retryAt());
        }
    }

    public function testRefreshFailurePreservesPrevious(): void
    {
        $failure = new \RuntimeException('Redis недоступен');
        $lock = $this->createStub(LockInterface::class);
        $lock->method('isExpired')->willReturn(false);
        $lock->method('isAcquired')->willReturn(true);
        $lock->method('refresh')->willThrowException($failure);

        try {
            new RedisRateSetLoadLockGuard($lock, 30.0, new NullLogger(), [])->refresh();
            self::fail('Ожидался сбой продления');
        } catch (RateSetLoadLockFailure $e) {
            self::assertSame($failure, $e->getPrevious());
        }
    }
}
