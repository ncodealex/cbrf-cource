<?php

declare(strict_types=1);

namespace App\Cources\Endpoints\Console\GetExchangeRate;

use App\Cources\Application\UseCase\GetExchangeRate\GetExchangeRateHandler;
use App\Cources\Application\UseCase\GetExchangeRate\GetExchangeRateQuery;
use App\Cources\Application\UseCase\GetExchangeRate\GetExchangeRateResult;
use App\Support\Retry\Application\RetryableFailure;
use App\Support\Retry\Application\RetryDecision;
use App\Support\Retry\Application\RetryPolicy;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class RetryingGetExchangeRate
{
    private const int WAIT_BUDGET_MS = 300;

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
            ->modify(\sprintf('+%d milliseconds', self::WAIT_BUDGET_MS));

        for ($attempt = 1;; ++$attempt) {
            try {
                return ($this->handler)($query);
            } catch (RetryableFailure $failure) {
                $decision = $this->policy->decide($failure, $attempt);

                if (!$this->waitForRetry($decision, $deadline)) {
                    throw $failure;
                }
            }
        }
    }

    private function waitForRetry(RetryDecision $decision, DatePoint $deadline): bool
    {
        if (!$decision->allowed || null === $decision->retryAt) {
            return false;
        }

        $now = DatePoint::createFromInterface($this->clock->now());
        if ($now >= $deadline || $decision->retryAt >= $deadline) {
            return false;
        }

        $delay = max(
            0.0,
            (float) $decision->retryAt->format('U.u') - (float) $now->format('U.u'),
        );
        if ($delay > 0) {
            $this->clock->sleep($delay);
        }

        $now = DatePoint::createFromInterface($this->clock->now());

        return $now >= $decision->retryAt && $now < $deadline;
    }
}
