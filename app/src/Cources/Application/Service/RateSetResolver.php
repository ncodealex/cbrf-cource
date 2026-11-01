<?php

declare(strict_types=1);

namespace App\Cources\Application\Service;

use App\Cources\Application\Contract\ExternalRateSetGatewayDatePolicyInterface;
use App\Cources\Application\Contract\ExternalRateSetGatewayInterface;
use App\Cources\Application\Contract\RateSetCacheInterface;
use App\Cources\Application\Contract\RateSetProviderInterface;
use App\Cources\Application\Contract\RateSetRepositoryInterface;
use App\Cources\Application\Exception\RateSetCacheFailure;
use App\Cources\Application\Exception\RateSetNotFound;
use App\Cources\Domain\Entity\RateSet;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(RateSetProviderInterface::class)]
final readonly class RateSetResolver implements RateSetProviderInterface
{
    public function __construct(
        private RateSetCacheInterface $cache,
        private RateSetRepositoryInterface $repository,
        private ExternalRateSetGatewayInterface $gateway,
        private ExternalRateSetGatewayDatePolicyInterface $gatewayDatePolicy,
        private LoggerInterface $logger,
    ) {
    }

    public function resolve(DatePoint $requestDate): RateSet
    {
        $this->assert($requestDate);
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

    protected function assert(DatePoint $requestDate): void
    {
        $this->gatewayDatePolicy->assertAllowed($requestDate);
    }
}
