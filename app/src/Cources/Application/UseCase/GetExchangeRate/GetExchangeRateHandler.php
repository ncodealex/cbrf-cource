<?php

declare(strict_types=1);

namespace App\Cources\Application\UseCase\GetExchangeRate;

use App\Cources\Application\Contract\RateSetProviderInterface;
use App\Cources\Domain\ExchangeRateCalculator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class GetExchangeRateHandler
{
    public function __construct(
        #[Autowire(lazy: true)]
        private RateSetProviderInterface $provider,
        private ExchangeRateCalculator $calculator
    ) {
    }

    public function __invoke(GetExchangeRateQuery $query): GetExchangeRateResult
    {
        $current  = $this->provider->resolve($query->requestedDate);
        $previous = $this->provider->resolve($current->effectiveDate->modify('-1 day'));
        $rate     = $this->calculator->calculate($current, $previous, $query->pair);

        return GetExchangeRateResult::fromDomain(
            $query->requestedDate,
            $query->pair,
            $rate
        );
    }
}
