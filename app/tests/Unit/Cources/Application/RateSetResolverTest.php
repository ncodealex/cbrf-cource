<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cources\Application;

use App\Courses\Application\Contract\ExternalRateSetGatewayDatePolicyInterface;
use App\Courses\Application\Contract\ExternalRateSetGatewayInterface;
use App\Courses\Application\Contract\RateSetCacheInterface;
use App\Courses\Application\Contract\RateSetLoadLockGuard;
use App\Courses\Application\Contract\RateSetLoadLockInterface;
use App\Courses\Application\Contract\RateSetRepositoryInterface;
use App\Courses\Application\Exception\RateSetCacheFailure;
use App\Courses\Application\Exception\RateSetConflict;
use App\Courses\Application\Exception\RateSetLoadBusy;
use App\Courses\Application\Exception\RateSetLoadLockFailure;
use App\Courses\Application\Exception\RateSetNotFound;
use App\Courses\Application\Service\RateSetResolver;
use App\Courses\Infrastructure\Cbr\Exception\CbrUnavailable;
use App\Shared\Domain\DatePointParser;
use App\Tests\Unit\Cources\Domain\RateSets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Clock\DatePoint;
use Throwable;

final class RateSetResolverTest extends TestCase
{
    private DatePoint $date;

    private RateSetCacheInterface&MockObject $cache;

    private RateSetRepositoryInterface&MockObject $repository;

    private ExternalRateSetGatewayInterface&MockObject $gateway;

    private ExternalRateSetGatewayDatePolicyInterface&MockObject $datePolicy;

    private RateSetLoadLockInterface&MockObject $loadLock;

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function lockFailures(): iterable
    {
        yield 'занято' => [new RateSetLoadBusy()];
        yield 'сбой Store' => [new RateSetLoadLockFailure()];
    }

    /**
     * @return iterable<string, array{string, Throwable}>
     */
    public static function sourceFailures(): iterable
    {
        yield 'Gateway' => ['fetch', new CbrUnavailable('Ошибка ЦБ')];
        yield 'Repository' => ['save', new RuntimeException('Ошибка PostgreSQL')];
        yield 'конфликт' => ['save', new RateSetConflict('Конфликт курсов')];
    }

    public function testCacheHitSkipsDatabaseAndLock(): void
    {
        $set = RateSets::saturday();
        $this->cache->expects(self::once())->method('get')->with($this->date)->willReturn($set);
        $this->cache->expects(self::never())->method('put');
        $this->repository->expects(self::never())->method('get');
        $this->gateway->expects(self::never())->method('fetch');
        $this->loadLock->expects(self::never())->method('synchronized');

        self::assertSame($set, $this->resolver()->resolve($this->date));
    }

    private function resolver(): RateSetResolver
    {
        return new RateSetResolver(
            $this->cache,
            $this->repository,
            $this->gateway,
            $this->datePolicy,
            new NullLogger(),
            $this->loadLock,
        );
    }

    public function testDatabaseHitWarmsCacheWithoutLock(): void
    {
        $set = RateSets::saturday();
        $this->cache->expects(self::once())->method('get')->willThrowException(new RateSetNotFound());
        $this->repository->expects(self::once())->method('get')->with($this->date)->willReturn($set);
        $this->cache->expects(self::once())->method('put')->with($this->date, $set);
        $this->gateway->expects(self::never())->method('fetch');
        $this->loadLock->expects(self::never())->method('synchronized');

        self::assertSame($set, $this->resolver()->resolve($this->date));
    }

    public function testDatabaseIsCheckedAgainAfterLock(): void
    {
        $set = RateSets::saturday();
        $this->cache->method('get')->willThrowException(new RateSetNotFound());
        $this->repository->expects(self::exactly(2))->method('get')
            ->with($this->date)
            ->willReturnOnConsecutiveCalls(self::throwException(new RateSetNotFound()), $set);
        $this->gateway->expects(self::never())->method('fetch');
        $this->repository->expects(self::never())->method('save');
        $this->cache->expects(self::once())->method('put')->with($this->date, $set);
        $this->executeLockCallback();

        self::assertSame($set, $this->resolver()->resolve($this->date));
    }

    private function executeLockCallback(?RateSetLoadLockGuard $guard = null): void
    {
        $guard ??= $this->createStub(RateSetLoadLockGuard::class);
        $this->loadLock->expects(self::once())->method('synchronized')->with($this->date)
            ->willReturnCallback(static fn(DatePoint $date, callable $operation) => $operation($guard));
    }

    public function testGatewayResultIsSavedBeforeCacheAndGuardIsRefreshed(): void
    {
        $set    = RateSets::saturday();
        $events = [];
        $guard  = $this->createMock(RateSetLoadLockGuard::class);
        $guard->expects(self::exactly(2))->method('refresh')->willReturnCallback(
            static function () use (&$events): void {
                $events[] = 'refresh';
            },
        );
        $this->cache->method('get')->willThrowException(new RateSetNotFound());
        $this->repository->expects(self::exactly(2))->method('get')->willThrowException(new RateSetNotFound());
        $this->gateway->expects(self::once())->method('fetch')->with($this->date)->willReturnCallback(
            static function () use (&$events, $set) {
                $events[] = 'fetch';

                return $set;
            },
        );
        $this->repository->expects(self::once())->method('save')->with($this->date, $set)->willReturnCallback(
            static function () use (&$events): void {
                $events[] = 'save';
            },
        );
        $this->cache->expects(self::once())->method('put')->with($this->date, $set)->willReturnCallback(
            static function () use (&$events): void {
                $events[] = 'cache';
            },
        );
        $this->executeLockCallback($guard);

        self::assertSame($set, $this->resolver()->resolve($this->date));
        self::assertSame(['refresh', 'fetch', 'refresh', 'save', 'cache'], $events);
    }

    #[DataProvider('lockFailures')]
    public function testLockFailureSkipsGateway(Throwable $failure): void
    {
        $this->cache->method('get')->willThrowException(new RateSetNotFound());
        $this->repository->expects(self::once())->method('get')->willThrowException(new RateSetNotFound());
        $this->loadLock->expects(self::once())->method('synchronized')->willThrowException($failure);
        $this->gateway->expects(self::never())->method('fetch');
        $this->cache->expects(self::never())->method('put');
        $this->expectExceptionObject($failure);

        $this->resolver()->resolve($this->date);
    }

    #[DataProvider('sourceFailures')]
    public function testSourceFailurePropagatesWithoutCacheWrite(string $operation, Throwable $failure): void
    {
        $set = RateSets::saturday();
        $this->cache->method('get')->willThrowException(new RateSetNotFound());
        $this->repository->method('get')->willThrowException(new RateSetNotFound());
        $this->gateway->expects(self::once())->method('fetch')
            ->willReturnCallback(static function () use ($operation, $failure, $set) {
                if ('fetch' === $operation) {
                    throw $failure;
                }

                return $set;
            });
        if ('save' === $operation) {
            $this->repository->expects(self::once())->method('save')->willThrowException($failure);
        } else {
            $this->repository->expects(self::never())->method('save');
        }
        $this->cache->expects(self::never())->method('put');
        $this->executeLockCallback();
        $this->expectExceptionObject($failure);

        $this->resolver()->resolve($this->date);
    }

    public function testCacheFailuresDoNotStopDatabaseResolution(): void
    {
        $set = RateSets::saturday();
        $this->cache->expects(self::once())->method('get')->willThrowException(new RateSetCacheFailure());
        $this->repository->expects(self::once())->method('get')->willReturn($set);
        $this->cache->expects(self::once())->method('put')->willThrowException(new RateSetCacheFailure());
        $this->loadLock->expects(self::never())->method('synchronized');
        $this->gateway->expects(self::never())->method('fetch');

        self::assertSame($set, $this->resolver()->resolve($this->date));
    }

    protected function setUp(): void
    {
        $this->date       = DatePointParser::parse('2026-10-26');
        $this->cache      = $this->createMock(RateSetCacheInterface::class);
        $this->repository = $this->createMock(RateSetRepositoryInterface::class);
        $this->gateway    = $this->createMock(ExternalRateSetGatewayInterface::class);
        $this->datePolicy = $this->createMock(ExternalRateSetGatewayDatePolicyInterface::class);
        $this->loadLock   = $this->createMock(RateSetLoadLockInterface::class);
        $this->datePolicy->expects(self::once())->method('assertAllowed')->with($this->date);
    }
}
