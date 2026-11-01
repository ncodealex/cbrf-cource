<?php

declare(strict_types=1);

namespace App\Cources\Application\Service;

use App\Cources\Application\Contract\ExternalRateSetGatewayInterface;
use App\Cources\Application\Contract\RateSetCacheInterface;
use App\Cources\Application\Contract\RateSetRepositoryInterface;
use App\Cources\Application\Exception\RateSetCacheFailure;
use App\Cources\Application\Exception\RateSetNotFound;
use App\Cources\Domain\Entity\RateSet;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\DatePoint;

final readonly class RateSetResolver
{
    public function __construct(
        private RateSetCacheInterface $cache,
        private RateSetRepositoryInterface $repository,
        private ExternalRateSetGatewayInterface $gateway,
        private LoggerInterface $logger,
    ) {
    }

    public function resolve(DatePoint $requestDate): RateSet
    {
        try {
            return $this->cache->get($requestDate);
        } catch (RateSetNotFound) {
            // Отсутствие записи в кеше — штатное событие.
        } catch (RateSetCacheFailure $e) {
            $this->logger->warning('Не удалось прочитать кеш курсов', ['exception' => $e]);
        }
        try {
            $rateSet = $this->repository->get($requestDate);
        } catch (RateSetNotFound) {
            $rateSet = $this->gateway->fetch($requestDate);
            $this->repository->save($requestDate, $rateSet);
        }
        try {
            $this->cache->put($requestDate, $rateSet);
        } catch (RateSetCacheFailure $e) {
            $this->logger->warning('Не удалось заполнить кеш курсов', ['exception' => $e]);
        }

        return $rateSet;
    }
}
