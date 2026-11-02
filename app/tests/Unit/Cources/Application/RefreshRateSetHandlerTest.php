<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cources\Application;

use App\Cources\Application\Contract\ExternalRateSetGatewayDatePolicyInterface;
use App\Cources\Application\Contract\RateImportJobRepositoryInterface;
use App\Cources\Application\Contract\RateSetCacheInterface;
use App\Cources\Application\Contract\RateSetProviderInterface;
use App\Cources\Application\Contract\RateSetRepositoryInterface;
use App\Cources\Application\Exception\RateSetCacheFailure;
use App\Cources\Application\UseCase\RefreshRateSet\RefreshRateSetHandler;
use App\Shared\Domain\DatePointParser;
use App\Tests\Unit\Cources\Domain\RateSets;
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
        $repository->expects(self::once())->method('delete')->with($date)->willReturnCallback(
            static function () use (&$events): void {
                $events[] = 'repository.delete';
            },
        );
        $provider->expects(self::once())->method('resolve')->with($date)->willReturnCallback(
            static function () use (&$events, $set) {
                $events[] = 'provider.resolve';

                return $set;
            },
        );

        $result = (new RefreshRateSetHandler($policy, $cache, $repository, $provider, $this->createStub(RateImportJobRepositoryInterface::class)))($date);

        self::assertSame($set, $result);
        self::assertSame([
            'validate',
            'cache.delete:1994-02-01,1994-02-02',
            'repository.delete',
            'provider.resolve',
        ], $events);
    }

    public function testCacheFailurePreventsDatabaseDeleteAndReload(): void
    {
        $date = DatePointParser::parse('1994-02-01');
        $policy = $this->createStub(ExternalRateSetGatewayDatePolicyInterface::class);
        $cache = $this->createMock(RateSetCacheInterface::class);
        $repository = $this->createMock(RateSetRepositoryInterface::class);
        $provider = $this->createMock(RateSetProviderInterface::class);
        $repository->method('relatedRequestDates')->willReturn([$date]);
        $cache->expects(self::once())->method('delete')->with($date)
            ->willThrowException(new RateSetCacheFailure('Redis недоступен'));
        $repository->expects(self::never())->method('delete');
        $provider->expects(self::never())->method('resolve');

        $this->expectException(RateSetCacheFailure::class);
        (new RefreshRateSetHandler($policy, $cache, $repository, $provider, $this->createStub(RateImportJobRepositoryInterface::class)))($date);
    }

    public function testDeletesRequestedDateFromCacheWhenDatabaseHasNoRelations(): void
    {
        $date = DatePointParser::parse('1994-02-01');
        $set = RateSets::saturday();
        $policy = $this->createStub(ExternalRateSetGatewayDatePolicyInterface::class);
        $cache = $this->createMock(RateSetCacheInterface::class);
        $repository = $this->createMock(RateSetRepositoryInterface::class);
        $provider = $this->createStub(RateSetProviderInterface::class);
        $repository->method('relatedRequestDates')->willReturn([]);
        $cache->expects(self::once())->method('delete')->with($date);
        $repository->expects(self::once())->method('delete')->with($date);
        $provider->method('resolve')->willReturn($set);

        self::assertSame($set, (new RefreshRateSetHandler($policy, $cache, $repository, $provider, $this->createStub(RateImportJobRepositoryInterface::class)))($date));
    }
}
