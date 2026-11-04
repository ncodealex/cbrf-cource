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
        private ExchangeRateCalculator $calculator,
    ) {
    }

    public function __invoke(GetExchangeRateQuery $query): GetExchangeRateResult
    {
        // 1. Получить набор для запрошенной даты и предыдущий набор перед датой действия.
        $current = $this->provider->resolve($query->requestedDate);
        $previous = $this->provider->resolve($current->effectiveDate->modify('-1 day'));

        // 2. Рассчитать курс пары и изменение относительно предыдущего набора.
        $rate = $this->calculator->calculate($current, $previous, $query->pair);

        // 3. Подготовить результат с точностью исходной котировки для вывода.
        return GetExchangeRateResult::fromDomain(
            $query->requestedDate,
            $query->pair,
            $rate,
        );
    }
}
