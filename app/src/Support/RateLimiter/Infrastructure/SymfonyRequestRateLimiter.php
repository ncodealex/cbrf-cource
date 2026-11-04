<?php

declare(strict_types=1);

namespace App\Support\RateLimiter\Infrastructure;

use App\Support\RateLimiter\Application\RateLimitDecision;
use App\Support\RateLimiter\Application\RequestRateLimiter;
use App\Support\RateLimiter\Application\RequestRateLimiterFailure;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

#[AsAlias(RequestRateLimiter::class)]
final readonly class SymfonyRequestRateLimiter implements RequestRateLimiter
{
    public function __construct(
        #[Target('cbr')]
        private RateLimiterFactoryInterface $factory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function acquire(): RateLimitDecision
    {
        try {
            $limit = $this->factory->create('cbr.global')->consume(1);

            return new RateLimitDecision($limit->isAccepted(), DatePoint::createFromInterface($limit->getRetryAfter()));
        } catch (\Throwable $e) {
            $this->logger->warning('Не удалось проверить глобальный лимит ЦБ', [
                'limiter_key' => 'cbr.global',
                'exception_class' => $e::class,
            ]);
            throw new RequestRateLimiterFailure(previous: $e);
        }
    }
}
