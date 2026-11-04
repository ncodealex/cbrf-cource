<?php

declare(strict_types=1);

namespace App\Tests\Unit\Courses\Application;

use App\Courses\Application\Contract\ExternalRateSetGatewayDatePolicyInterface;
use App\Courses\Application\Contract\RateSetCacheInterface;
use App\Courses\Application\Contract\RateSetProviderInterface;
use App\Courses\Application\Contract\RateSetRefreshStorageInterface;
use App\Courses\Application\Contract\RateSetRepositoryInterface;
use App\Courses\Application\Exception\RateSetCacheFailure;
use App\Courses\Application\UseCase\RefreshRateSet\RefreshRateSetHandler;
use App\Shared\Domain\DatePointParser;
use App\Tests\Unit\Courses\Domain\RateSets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\DatePoint;

final class RefreshRateSetHandlerTest extends TestCase
{
    public function testDeletesAllRelatedCacheEntriesBeforeDeletingSetAndResolvingAgain(): void
    {
        $date = DatePointParser::parse('1994-02-01');
        $relatedDate = DatePointParser::parse('1994-02-02');
        $set = RateSets::saturday();
        $events = [];
        $policy = $this->createMock(ExternalRateSetGatewayDatePolicyInterface::class);
        $cache = $this->createMock(RateSetCacheInterface::class);
        $repository = $this->createMock(RateSetRepositoryInterface::class);
        $provider = $this->createMock(RateSetProviderInterface::class);
        $storage = $this->createMock(RateSetRefreshStorageInterface::class);

        $policy->expects(self::once())->method('assertAllowed')->with($date)->willReturnCallback(
            static function () use (&$events): void {
                $events[] = 'validate';
            },
        );
        $repository->expects(self::once())->method('relatedRequestDates')->with($date)
            ->willReturn([$date, $relatedDate]);
        $cache->expects(self::once())->method('delete')->with($date, $relatedDate)->willReturnCallback(
            static function (DatePoint ...$cacheDates) use (&$events): void {
                $events[] = 'cache.delete:'.implode(',', array_map(
                    static fn (DatePoint $cacheDate): string => $cacheDate->format('Y-m-d'),
                    $cacheDates,
                ));
            },
        );
        $storage->expects(self::once())->method('invalidate')->with($date, $date, $relatedDate)->willReturnCallback(
            static function () use (&$events): void {
                $events[] = 'storage.invalidate';
            },
        );
        $provider->expects(self::once())->method('resolve')->with($date)->willReturnCallback(
            static function () use (&$events, $set) {
                $events[] = 'provider.resolve';

                return $set;
            },
        );

        $result = (new RefreshRateSetHandler($policy, $cache, $repository, $provider, $storage))($date);

        self::assertSame($set, $result);
        self::assertSame([
            'validate',
            'cache.delete:1994-02-01,1994-02-02',
            'storage.invalidate',
            'provider.resolve',
        ], $events);
    }

    public function testCacheFailurePreventsDatabaseDeleteAndReload(): void
    {
        $date = DatePointParser::parse('1994-02-01');
        $policy = $this->createStub(ExternalRateSetGatewayDatePolicyInterface::class);
        $cache = $this->createMock(RateSetCacheInterface::class);
        $repository = $this->createStub(RateSetRepositoryInterface::class);
        $provider = $this->createMock(RateSetProviderInterface::class);
        $storage = $this->createMock(RateSetRefreshStorageInterface::class);
        $repository->method('relatedRequestDates')->willReturn([$date]);
        $cache->expects(self::once())->method('delete')->with($date)
            ->willThrowException(new RateSetCacheFailure('Redis недоступен'));
        $storage->expects(self::never())->method('invalidate');
        $provider->expects(self::never())->method('resolve');

        $this->expectException(RateSetCacheFailure::class);
        (new RefreshRateSetHandler($policy, $cache, $repository, $provider, $storage))($date);
    }

    public function testDeletesRequestedDateFromCacheWhenDatabaseHasNoRelations(): void
    {
        $date = DatePointParser::parse('1994-02-01');
        $set = RateSets::saturday();
        $policy = $this->createStub(ExternalRateSetGatewayDatePolicyInterface::class);
        $cache = $this->createMock(RateSetCacheInterface::class);
        $repository = $this->createStub(RateSetRepositoryInterface::class);
        $provider = $this->createStub(RateSetProviderInterface::class);
        $storage = $this->createMock(RateSetRefreshStorageInterface::class);
        $repository->method('relatedRequestDates')->willReturn([]);
        $cache->expects(self::once())->method('delete')->with($date);
        $storage->expects(self::once())->method('invalidate')->with($date, $date);
        $provider->method('resolve')->willReturn($set);

        self::assertSame($set, (new RefreshRateSetHandler($policy, $cache, $repository, $provider, $storage))($date));
    }
}
