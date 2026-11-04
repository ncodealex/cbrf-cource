<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cources\Endpoints\Console\GetExchangeRate;

use App\Courses\Application\Contract\RateSetProviderInterface;
use App\Courses\Application\Exception\RateSetLoadBusy;
use App\Courses\Application\Factory\CurrencyPairFactory;
use App\Courses\Application\UseCase\GetExchangeRate\GetExchangeRateHandler;
use App\Courses\Application\UseCase\GetExchangeRate\GetExchangeRateQuery;
use App\Courses\Domain\ExchangeRateCalculator;
use App\Courses\Endpoints\Console\GetExchangeRate\RetryingGetExchangeRate;
use App\Courses\Infrastructure\Cbr\Exception\CbrRequestRateLimitExceeded;
use App\Support\Retry\Application\RetryPolicy;
use App\Tests\Unit\Cources\Domain\RateSets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\Clock\MockClock;

final class RetryingGetExchangeRateTest extends TestCase
{
    private MockClock $clock;

    /**
     * @return iterable<string, array{int}>
     */
    public static function retryDelays(): iterable
    {
        yield 'one second' => [1];
        yield 'three seconds' => [3];
        yield 'four seconds' => [4];
    }

    public function testRetriesTemporaryFailureAndReturnsResult(): void
    {
        $calls    = 0;
        $provider = $this->createStub(RateSetProviderInterface::class);
        $provider->method('resolve')->willReturnCallback(static function () use (&$calls) {
            ++$calls;
            if (1 === $calls) {
                throw new RateSetLoadBusy();
            }

            return 2 === $calls ? RateSets::saturday() : RateSets::friday();
        });

        $result = ($this->retryingHandler($provider))($this->query());

        self::assertSame('81.4500', (string)$result->rate);
        self::assertSame(3, $calls);
        self::assertSame('2026-11-02 12:00:00.100000', $this->clock->now()->format('Y-m-d H:i:s.u'));
    }

    private function retryingHandler(RateSetProviderInterface $provider): RetryingGetExchangeRate
    {
        return new RetryingGetExchangeRate(
            new GetExchangeRateHandler($provider, new ExchangeRateCalculator()),
            new RetryPolicy($this->clock, 3, 100, 200, 0),
            $this->clock,
        );
    }

    private function query(): GetExchangeRateQuery
    {
        return new GetExchangeRateQuery(
            new DatePoint('2026-10-26 Europe/Moscow'),
            new CurrencyPairFactory()->of('USD'),
        );
    }

    public function testRetryPolicyStopsAfterThirdAttempt(): void
    {
        $failure  = new RateSetLoadBusy();
        $calls    = 0;
        $provider = $this->createStub(RateSetProviderInterface::class);
        $provider->method('resolve')->willReturnCallback(static function () use (&$calls, $failure): never {
            ++$calls;
            throw $failure;
        });
        $this->expectExceptionObject($failure);

        try {
            ($this->retryingHandler($provider))($this->query());
        } finally {
            self::assertSame(3, $calls);
            self::assertSame('2026-11-02 12:00:00.300000', $this->clock->now()->format('Y-m-d H:i:s.u'));
        }
    }

    public function testExplicitRetryAtInsideBudgetIsRespected(): void
    {
        $calls    = 0;
        $failure  = new CbrRequestRateLimitExceeded(new DatePoint('2026-11-02 12:00:00.050000 Europe/Moscow'));
        $provider = $this->createStub(RateSetProviderInterface::class);
        $provider->method('resolve')->willReturnCallback(static function () use (&$calls, $failure) {
            ++$calls;
            if (1 === $calls) {
                throw $failure;
            }

            return 2 === $calls ? RateSets::saturday() : RateSets::friday();
        });

        ($this->retryingHandler($provider))($this->query());

        self::assertSame(3, $calls);
        self::assertSame('2026-11-02 12:00:00.050000', $this->clock->now()->format('Y-m-d H:i:s.u'));
    }

    public function testExplicitRetryAtOutsideBudgetIsNotWaitedFor(): void
    {
        $failure  = new CbrRequestRateLimitExceeded(new DatePoint('2026-11-02 12:00:40 Europe/Moscow'));
        $provider = $this->createMock(RateSetProviderInterface::class);
        $provider->expects(self::once())->method('resolve')->willThrowException($failure);
        $this->expectExceptionObject($failure);

        try {
            ($this->retryingHandler($provider))($this->query());
        } finally {
            self::assertSame('2026-11-02 12:00:00.000000', $this->clock->now()->format('Y-m-d H:i:s.u'));
        }
    }

    #[DataProvider('retryDelays')]
    public function testRetriesAtExplicitTimeWithinBudget(int $seconds): void
    {
        $calls    = 0;
        $retryAt  = new DatePoint('2026-11-02 12:00:00 Europe/Moscow')->modify('+' . $seconds . ' seconds');
        $failure  = new CbrRequestRateLimitExceeded($retryAt);
        $provider = $this->createStub(RateSetProviderInterface::class);
        $provider->method('resolve')->willReturnCallback(static function () use (&$calls, $failure) {
            ++$calls;
            if (1 === $calls) {
                throw $failure;
            }

            return 2 === $calls ? RateSets::saturday() : RateSets::friday();
        });

        ($this->retryingHandler($provider))($this->query());

        self::assertSame(3, $calls);
        self::assertSame($retryAt->format('U.u'), $this->clock->now()->format('U.u'));
    }

    public function testHandlerTimeConsumesSharedBudget(): void
    {
        $calls    = 0;
        $failure  = new RateSetLoadBusy();
        $provider = $this->createStub(RateSetProviderInterface::class);
        $provider->method('resolve')->willReturnCallback(function () use (&$calls, $failure): never {
            ++$calls;
            $this->clock->sleep(5);
            throw $failure;
        });

        $this->expectExceptionObject($failure);
        try {
            ($this->retryingHandler($provider))($this->query());
        } finally {
            self::assertSame(1, $calls);
        }
    }

    public function testPermanentFailureIsNotRetried(): void
    {
        $failure  = new RuntimeException('Постоянная ошибка');
        $provider = $this->createMock(RateSetProviderInterface::class);
        $provider->expects(self::once())->method('resolve')->willThrowException($failure);
        $this->expectExceptionObject($failure);

        ($this->retryingHandler($provider))($this->query());
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-02 12:00:00.000000 Europe/Moscow');
    }
}
