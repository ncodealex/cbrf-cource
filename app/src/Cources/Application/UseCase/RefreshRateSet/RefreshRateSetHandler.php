<?php

declare(strict_types=1);

namespace App\Cources\Application\UseCase\RefreshRateSet;

use App\Cources\Application\Contract\ExternalRateSetGatewayDatePolicyInterface;
use App\Cources\Application\Contract\RateImportJobRepositoryInterface;
use App\Cources\Application\Contract\RateSetCacheInterface;
use App\Cources\Application\Contract\RateSetProviderInterface;
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
        private RateImportJobRepositoryInterface $importJobs,
    ) {
    }

    public function __invoke(DatePoint $requestDate): RateSet
    {
        $this->datePolicy->assertAllowed($requestDate);
        $relatedDates = $this->repository->relatedRequestDates($requestDate);
        $this->cache->delete(...([] === $relatedDates ? [$requestDate] : $relatedDates));
        $this->importJobs->delete(...([] === $relatedDates ? [$requestDate] : $relatedDates));
        $this->repository->delete($requestDate);

        return $this->provider->resolve($requestDate);
    }
}
