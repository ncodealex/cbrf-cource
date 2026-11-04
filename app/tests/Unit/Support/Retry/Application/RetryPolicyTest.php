<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support\Retry\Application;

use App\Support\Retry\Application\RetryableFailure;
use App\Support\Retry\Application\RetryPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\Clock\MockClock;

final class RetryPolicyTest extends TestCase
{
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-02 12:00:00.000000 Europe/Moscow');
    }

    public function testNonRetryableFailureIsDenied(): void
    {
        $decision = $this->policy()->decide(new \RuntimeException('Постоянная ошибка'), 1);

        self::assertFalse($decision->allowed);
        self::assertNull($decision->retryAt);
    }

    public function testAttemptLimitIsInclusive(): void
    {
        $decision = $this->policy(maxAttempts: 3)->decide($this->retryable(), 3);

        self::assertFalse($decision->allowed);
        self::assertNull($decision->retryAt);
    }

    public function testExplicitFutureRetryAtIsPreserved(): void
    {
        $retryAt = new DatePoint('2026-11-02 12:00:40 Europe/Moscow');

        $decision = $this->policy()->decide($this->retryable($retryAt), 1);

        self::assertTrue($decision->allowed);
        self::assertSame($retryAt, $decision->retryAt);
    }

    public function testPastRetryAtIsClampedToNow(): void
    {
        $decision = $this->policy()->decide(
            $this->retryable(new DatePoint('2026-11-02 11:59:00 Europe/Moscow')),
            1,
        );

        self::assertTrue($decision->allowed);
        self::assertSame('2026-11-02 12:00:00.000000', $decision->retryAt?->format('Y-m-d H:i:s.u'));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function backoff(): iterable
    {
        yield 'первая попытка' => [1, '2026-11-02 12:00:00.100000'];
        yield 'вторая попытка' => [2, '2026-11-02 12:00:00.200000'];
        yield 'ограничение максимума' => [3, '2026-11-02 12:00:00.250000'];
    }

    #[DataProvider('backoff')]
    public function testExponentialBackoffWithoutJitter(int $attempt, string $expected): void
    {
        $decision = $this->policy(maxAttempts: 5, maxDelayMs: 250)->decide($this->retryable(), $attempt);

        self::assertTrue($decision->allowed);
        self::assertSame($expected, $decision->retryAt?->format('Y-m-d H:i:s.u'));
    }

    public function testAttemptMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->policy()->decide($this->retryable(), 0);
    }

    /**
     * @return iterable<string, array{int, int, int, int}>
     */
    public static function invalidConfiguration(): iterable
    {
        yield 'нет попыток' => [0, 100, 200, 0];
        yield 'нулевая задержка' => [3, 0, 200, 0];
        yield 'максимум меньше начальной задержки' => [3, 200, 100, 0];
        yield 'отрицательный jitter' => [3, 100, 200, -1];
        yield 'jitter больше 100%' => [3, 100, 200, 101];
    }

    #[DataProvider('invalidConfiguration')]
    public function testRejectsInvalidConfiguration(
        int $maxAttempts,
        int $initialDelayMs,
        int $maxDelayMs,
        int $jitterPercent,
    ): void {
        $this->expectException(\InvalidArgumentException::class);

        new RetryPolicy($this->clock, $maxAttempts, $initialDelayMs, $maxDelayMs, $jitterPercent);
    }

    private function policy(int $maxAttempts = 3, int $maxDelayMs = 200): RetryPolicy
    {
        return new RetryPolicy($this->clock, $maxAttempts, 100, $maxDelayMs, 0);
    }

    private function retryable(?DatePoint $retryAt = null): RetryableFailure
    {
        return new class($retryAt) extends \RuntimeException implements RetryableFailure {
            public function __construct(
                private readonly ?DatePoint $nextAttempt,
            ) {
                parent::__construct(
                    'Временная ошибка',
                );
            }

            public function retryAt(): ?DatePoint
            {
                return $this->nextAttempt;
            }
        };
    }
}
