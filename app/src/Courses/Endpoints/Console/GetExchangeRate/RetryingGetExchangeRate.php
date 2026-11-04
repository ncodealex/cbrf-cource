<?php

declare(strict_types=1);

namespace App\Courses\Endpoints\Console\GetExchangeRate;

use App\Courses\Application\UseCase\GetExchangeRate\GetExchangeRateHandler;
use App\Courses\Application\UseCase\GetExchangeRate\GetExchangeRateQuery;
use App\Courses\Application\UseCase\GetExchangeRate\GetExchangeRateResult;
use App\Support\Retry\Application\RetryableFailure;
use App\Support\Retry\Application\RetryPolicy;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class RetryingGetExchangeRate
{
    private const int WAIT_BUDGET_SECONDS = 5;

    public function __construct(
        private GetExchangeRateHandler $handler,
        #[Autowire(service: 'app.cbr.console_retry_policy')]
        private RetryPolicy $policy,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(GetExchangeRateQuery $query): GetExchangeRateResult
    {
        $deadline = DatePoint::createFromInterface($this->clock->now())
            ->modify('+'.self::WAIT_BUDGET_SECONDS.' seconds');

        for ($attempt = 1;; ++$attempt) {
            try {
                return ($this->handler)($query);
            } catch (RetryableFailure $failure) {
                $decision = $this->policy->decide($failure, $attempt);

                $now = DatePoint::createFromInterface($this->clock->now());
                if (!$decision->allowed || null === $decision->retryAt || $now >= $deadline || $decision->retryAt >= $deadline) {
                    throw $failure;
                }

                $delay = max(0.0, (float) $decision->retryAt->format('U.u') - (float) $now->format('U.u'));
                if ($delay > 0) {
                    $this->clock->sleep($delay);
                }
                if ($this->clock->now() >= $deadline) {
                    throw $failure;
                }
            }
        }
    }
}
