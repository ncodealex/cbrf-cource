<?php

declare(strict_types=1);

namespace App\Tests\Integration\Courses;

use App\Courses\Application\Contract\RateSetLoadLockGuard;
use App\Courses\Application\Contract\RateSetLoadLockInterface;
use App\Courses\Application\Exception\RateSetLoadBusy;
use App\Courses\Application\Exception\RateSetLoadLockFailure;
use App\Courses\Infrastructure\Lock\RedisRateSetLoadLock;
use App\Tests\Unit\Courses\Domain\RateSets;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\RedisStore;

final class RedisRateSetLoadLockTest extends KernelTestCase
{
    public function testSameDateIsRejectedWithoutWaitingAndReleasedAfterOperation(): void
    {
        $lock = self::getContainer()->get(RateSetLoadLockInterface::class);
        $date = $this->uniqueDate();
        $busy = null;

        $result = $lock->synchronized($date, static function (RateSetLoadLockGuard $guard) use ($lock, $date, &$busy) {
            $guard->refresh();
            $startedAt = microtime(true);
            try {
                $lock->synchronized($date, static fn () => RateSets::saturday());
            } catch (RateSetLoadBusy $e) {
                $busy = $e;
            }

            self::assertLessThan(0.5, microtime(true) - $startedAt);

            return RateSets::saturday();
        });

        self::assertInstanceOf(RateSetLoadBusy::class, $busy);
        self::assertSame(RateSets::saturday()->contentHash(), $result->contentHash());
        self::assertSame(
            RateSets::saturday()->contentHash(),
            $lock->synchronized($date, static fn () => RateSets::saturday())->contentHash(),
        );
    }

    private function uniqueDate(): DatePoint
    {
        return new DatePoint('2100-01-01 +'.random_int(0, 100000).' days');
    }

    public function testDifferentDatesHaveIndependentLocks(): void
    {
        $lock = self::getContainer()->get(RateSetLoadLockInterface::class);
        $firstDate = $this->uniqueDate();
        $secondDate = $firstDate->modify('+1 day');

        $result = $lock->synchronized(
            $firstDate,
            static fn () => $lock->synchronized($secondDate, static fn () => RateSets::friday()),
        );

        self::assertSame(RateSets::friday()->contentHash(), $result->contentHash());
    }

    public function testCallbackFailureIsPropagatedAndLockIsReleased(): void
    {
        $lock = self::getContainer()->get(RateSetLoadLockInterface::class);
        $date = $this->uniqueDate();
        $failure = new \RuntimeException('Сбой операции');

        try {
            $lock->synchronized($date, static function () use ($failure): never {
                throw $failure;
            });
            self::fail('Ожидался сбой операции');
        } catch (\RuntimeException $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame(
            RateSets::saturday()->contentHash(),
            $lock->synchronized($date, static fn () => RateSets::saturday())->contentHash(),
        );
    }

    public function testUnavailableRedisFailsClosedAndPreservesCause(): void
    {
        $lock = new RedisRateSetLoadLock(
            new LockFactory(new RedisStore(new \Redis())),
            new NullLogger(),
            30.0,
        );

        try {
            $lock->synchronized($this->uniqueDate(), static fn () => RateSets::saturday());
            self::fail('Ожидался сбой Redis Lock');
        } catch (RateSetLoadLockFailure $e) {
            self::assertNotNull($e->getPrevious());
        }
    }
}
