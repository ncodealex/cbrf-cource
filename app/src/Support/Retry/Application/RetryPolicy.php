<?php

declare(strict_types=1);

namespace App\Support\Retry\Application;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class RetryPolicy
{
    public function __construct(
        private ClockInterface $clock,
        private int $maxAttempts,
        private int $initialDelayMs,
        private int $maxDelayMs,
        private int $jitterPercent,
    ) {
        if ($maxAttempts < 1 || $initialDelayMs < 1 || $maxDelayMs < $initialDelayMs || $jitterPercent < 0 || $jitterPercent > 100) {
            throw new \InvalidArgumentException('Некорректные параметры политики повторов.');
        }
    }

    /** attempt — номер завершившегося вызова, начиная с единицы. Политика не резервирует ресурсы. */
    public function decide(\Throwable $failure, int $attempt): RetryDecision
    {
        if ($attempt < 1) {
            throw new \InvalidArgumentException('Номер попытки должен быть положительным.');
        }
        if (!$failure instanceof RetryableFailure || $attempt >= $this->maxAttempts) {
            return new RetryDecision(false, null);
        }
        $now = DatePoint::createFromInterface($this->clock->now());
        $retryAt = $failure->retryAt();
        if (null !== $retryAt) {
            return new RetryDecision(true, $retryAt > $now ? $retryAt : $now);
        }
        $delay = min($this->maxDelayMs, $this->initialDelayMs * 2 ** min(30, $attempt - 1));
        $delay = (int) round($delay * random_int(100 - $this->jitterPercent, 100 + $this->jitterPercent) / 100);

        return new RetryDecision(true, $now->modify(\sprintf('+%d microseconds', $delay * 1000)));
    }
}
