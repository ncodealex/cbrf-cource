<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support\RateLimiter\Infrastructure;

use App\Support\RateLimiter\Application\RequestRateLimiterFailure;
use App\Support\RateLimiter\Infrastructure\SymfonyRequestRateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class SymfonyRequestRateLimiterTest extends TestCase
{
    /**
     * @return iterable<string, array{bool}>
     */
    public static function decisions(): iterable
    {
        yield 'токен получен' => [true];
        yield 'лимит исчерпан' => [false];
    }

    #[DataProvider('decisions')]
    public function testConsumesOneTokenAndMapsDecision(bool $accepted): void
    {
        $retryAt = new \DateTimeImmutable('2026-11-02T12:00:03+03:00');
        $limit = new RateLimit($accepted ? 0 : -1, $retryAt, $accepted, 1);
        $limiter = $this->createMock(LimiterInterface::class);
        $limiter->expects(self::once())->method('consume')->with(1)->willReturn($limit);
        $factory = $this->createMock(RateLimiterFactoryInterface::class);
        $factory->expects(self::once())->method('create')->with('cbr.global')->willReturn($limiter);

        $decision = new SymfonyRequestRateLimiter($factory, new NullLogger())->acquire();

        self::assertSame($accepted, $decision->accepted);
        self::assertSame($retryAt->format('U.u'), $decision->retryAt->format('U.u'));
    }

    public function testWrapsInfrastructureFailureWithPrevious(): void
    {
        $failure = new \RuntimeException('Redis недоступен');
        $factory = $this->createStub(RateLimiterFactoryInterface::class);
        $factory->method('create')->willThrowException($failure);

        try {
            new SymfonyRequestRateLimiter($factory, new NullLogger())->acquire();
            self::fail('Ожидался сбой RateLimiter');
        } catch (RequestRateLimiterFailure $e) {
            self::assertSame($failure, $e->getPrevious());
            self::assertNull($e->retryAt());
        }
    }
}
