<?php

declare(strict_types=1);

namespace App\Support\RateLimiter\Infrastructure;

use App\Support\RateLimiter\Application\{RateLimitDecision, RequestRateLimiter};
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class SymfonyRequestRateLimiter implements RequestRateLimiter
{
    public function __construct(
        #[Autowire(service: 'limiter.cbr')]
        private RateLimiterFactory $factory
    ) {
    }

    public function acquire(float $maxWaitSeconds = 0): RateLimitDecision
    {
        $limiter = $this->factory->create('cbr.global');
        if ($maxWaitSeconds <= 0) {
            $limit = $limiter->consume(1);
            return new RateLimitDecision($limit->isAccepted(), DatePoint::createFromInterface($limit->getRetryAfter()));
        }
        try {
            $reservation = $limiter->reserve(1, $maxWaitSeconds);
            $reservation->wait();
            return new RateLimitDecision(true,
                DatePoint::createFromInterface($reservation->getRateLimit()->getRetryAfter()));
        } catch (\Symfony\Component\RateLimiter\Exception\MaxWaitDurationExceededException) {
            return new RateLimitDecision(false, DatePoint::createFromInterface($limiter->consume(0)->getRetryAfter()));
        }
    }
}
