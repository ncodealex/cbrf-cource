<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cources\Application;

use App\Cources\Application\Contract\ExternalRateSetGatewayInterface;
use App\Cources\Application\Contract\RateSetCacheInterface;
use App\Cources\Application\Contract\RateSetRepositoryInterface;
use App\Cources\Application\Exception\CbrUnavailable;
use App\Cources\Application\Exception\RateSetCacheFailure;
use App\Cources\Application\Exception\RateSetConflict;
use App\Cources\Application\Exception\RateSetNotFound;
use App\Cources\Application\Service\RateSetResolver;
use App\Cources\Domain\MoscowCalendar;
use App\Tests\Unit\Cources\Domain\RateSets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RateSetResolverTest extends TestCase
{
    public function testCacheHitSkipsOtherSources(): void
    {
        $set = RateSets::saturday();
        $cache = $this->createMock(RateSetCacheInterface::class);
        $db = $this->createMock(RateSetRepositoryInterface::class);
        $gateway = $this->createMock(ExternalRateSetGatewayInterface::class);
        $cache->expects(self::once())->method('get')->willReturn($set);
        $cache->expects(self::never())->method('put');
        $db->expects(self::never())->method('get');
        $db->expects(self::never())->method('save');
        $gateway->expects(self::never())->method('fetch');
        self::assertSame($set, new RateSetResolver($cache, $db, $gateway, new NullLogger())->resolve(MoscowCalendar::date('2026-10-26')));
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function cacheFailures(): iterable
    {
        yield 'промах и успешная запись' => [false, false];
        yield 'ошибка чтения' => [true, false];
        yield 'ошибка записи' => [false, true];
        yield 'обе ошибки' => [true, true];
    }

    #[DataProvider('cacheFailures')]
    public function testDatabaseHitAndCacheFailures(bool $getFails, bool $putFails): void
    {
        $date = MoscowCalendar::date('2026-10-26');
        $set = RateSets::saturday();
        $cache = $this->createMock(RateSetCacheInterface::class);
        $db = $this->createMock(RateSetRepositoryInterface::class);
        $gateway = $this->createMock(ExternalRateSetGatewayInterface::class);
        $cache->expects(self::once())->method('get')->with($date)->willThrowException($getFails ? new RateSetCacheFailure() : new RateSetNotFound());
        $put = $cache->expects(self::once())->method('put')->with($date, $set);
        if ($putFails) {
            $put->willThrowException(new RateSetCacheFailure());
        }
        $db->expects(self::once())->method('get')->with($date)->willReturn($set);
        $db->expects(self::never())->method('save');
        $gateway->expects(self::never())->method('fetch');
        self::assertSame($set, new RateSetResolver($cache, $db, $gateway, new NullLogger())->resolve($date));
    }

    #[DataProvider('cacheFailures')]
    public function testGatewayHitSavesBeforeFillingCache(bool $getFails, bool $putFails): void
    {
        $date = MoscowCalendar::date('2026-10-26');
        $set = RateSets::saturday();
        $events = [];
        $cache = $this->createMock(RateSetCacheInterface::class);
        $db = $this->createMock(RateSetRepositoryInterface::class);
        $gateway = $this->createMock(ExternalRateSetGatewayInterface::class);
        $cache->expects(self::once())->method('get')->willThrowException($getFails ? new RateSetCacheFailure() : new RateSetNotFound());
        $db->expects(self::once())->method('get')->with($date)->willThrowException(new RateSetNotFound());
        $gateway->expects(self::once())->method('fetch')->with($date)->willReturn($set);
        $db->expects(self::once())->method('save')->with($date, $set)->willReturnCallback(static function () use (&$events): void { $events[] = 'save'; });
        $cache->expects(self::once())->method('put')->with($date, $set)->willReturnCallback(static function () use (&$events, $putFails): void {
            $events[] = 'put';
            if ($putFails) {
                throw new RateSetCacheFailure();
            }
        });
        self::assertSame($set, new RateSetResolver($cache, $db, $gateway, new NullLogger())->resolve($date));
        self::assertSame(['save', 'put'], $events);
    }

    /** @return iterable<string, array{string, \Throwable}> */
    public static function sourceErrors(): iterable
    {
        yield 'ошибка БД' => ['get', new \RuntimeException('Ошибка PostgreSQL')];
        yield 'ошибка ЦБ' => ['fetch', new CbrUnavailable('Ошибка ЦБ')];
        yield 'ошибка сохранения' => ['save', new \RuntimeException('Ошибка PostgreSQL')];
        yield 'конфликт' => ['save', new RateSetConflict('Конфликт курсов')];
    }

    #[DataProvider('sourceErrors')]
    public function testPropagatesSourceErrors(string $operation, \Throwable $error): void
    {
        $cache = $this->createMock(RateSetCacheInterface::class);
        $db = $this->createMock(RateSetRepositoryInterface::class);
        $gateway = $this->createMock(ExternalRateSetGatewayInterface::class);
        $cache->expects(self::once())->method('get')->willThrowException(new RateSetNotFound());
        $cache->expects(self::never())->method('put');
        $db->expects(self::once())->method('get')->willThrowException('get' === $operation ? $error : new RateSetNotFound());
        if ('get' === $operation) {
            $gateway->expects(self::never())->method('fetch');
        } elseif ('fetch' === $operation) {
            $gateway->expects(self::once())->method('fetch')->willThrowException($error);
            $db->expects(self::never())->method('save');
        } else {
            $gateway->expects(self::once())->method('fetch')->willReturn(RateSets::saturday());
            $db->expects(self::once())->method('save')->willThrowException($error);
        }
        $this->expectExceptionObject($error);
        new RateSetResolver($cache, $db, $gateway, new NullLogger())->resolve(MoscowCalendar::date('2026-10-26'));
    }
}
