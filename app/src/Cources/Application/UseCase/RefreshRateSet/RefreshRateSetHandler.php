<?php

declare(strict_types=1);

namespace App\Cources\Application\UseCase\RefreshRateSet;

use App\Cources\Application\Contract\ExternalRateSetGatewayDatePolicyInterface;
use App\Cources\Application\Contract\RateSetCacheInterface;
use App\Cources\Application\Contract\RateSetProviderInterface;
use App\Cources\Application\Contract\RateSetRefreshStorageInterface;
use App\Cources\Application\Contract\RateSetRepositoryInterface;
use App\Cources\Domain\Entity\RateSet;
use Symfony\Component\Clock\DatePoint;

final readonly class RefreshRateSetHandler
{
    public function __construct(
        private ExternalRateSetGatewayDatePolicyInterface $datePolicy,
        private RateSetCacheInterface $cache,
        private RateSetRepositoryInterface $repository,
        private RateSetProviderInterface $provider,
        private RateSetRefreshStorageInterface $refreshStorage,
    ) {
    }

    public function __invoke(DatePoint $requestDate): RateSet
    {
        $this->datePolicy->assertAllowed($requestDate);
        $relatedDates = $this->repository->relatedRequestDates($requestDate);
        $this->cache->delete(...([] === $relatedDates ? [$requestDate] : $relatedDates));
        $this->refreshStorage->invalidate($requestDate, ...([] === $relatedDates ? [$requestDate] : $relatedDates));

        return $this->provider->resolve($requestDate);
    }
}
