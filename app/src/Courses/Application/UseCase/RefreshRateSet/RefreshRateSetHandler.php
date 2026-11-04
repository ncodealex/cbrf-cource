<?php

declare(strict_types=1);

namespace App\Courses\Application\UseCase\RefreshRateSet;

use App\Courses\Application\Contract\ExternalRateSetGatewayDatePolicyInterface;
use App\Courses\Application\Contract\RateSetCacheInterface;
use App\Courses\Application\Contract\RateSetProviderInterface;
use App\Courses\Application\Contract\RateSetRefreshStorageInterface;
use App\Courses\Application\Contract\RateSetRepositoryInterface;
use App\Courses\Domain\Entity\RateSet;
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
        // 1. Проверить дату и найти все календарные даты, связанные с набором.
        $this->datePolicy->assertAllowed($requestDate);
        $relatedDates = $this->repository->relatedRequestDates($requestDate);

        // 2. Очистить кеш и удалить сохранённый набор со связями.
        $this->cache->delete(...([] === $relatedDates ? [$requestDate] : $relatedDates));
        $this->refreshStorage->invalidate($requestDate, ...([] === $relatedDates ? [$requestDate] : $relatedDates));

        // 3. Заново получить и сохранить набор для исходной даты.
        return $this->provider->resolve($requestDate);
    }
}
