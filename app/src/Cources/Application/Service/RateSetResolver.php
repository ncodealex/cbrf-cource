<?php

declare(strict_types=1);

namespace App\Cources\Application\Service;

use App\Cources\Application\Contract\ExternalRateSetGatewayDatePolicyInterface;
use App\Cources\Application\Contract\ExternalRateSetGatewayInterface;
use App\Cources\Application\Contract\RateSetCacheInterface;
use App\Cources\Application\Contract\RateSetLoadLockGuard;
use App\Cources\Application\Contract\RateSetLoadLockInterface;
use App\Cources\Application\Contract\RateSetProviderInterface;
use App\Cources\Application\Contract\RateSetRepositoryInterface;
use App\Cources\Application\Exception\RateSetCacheFailure;
use App\Cources\Application\Exception\RateSetNotFound;
use App\Cources\Domain\Entity\RateSet;
use App\Shared\Domain\DateFormatInterface;
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
        private RateSetLoadLockInterface $loadLock,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function resolve(DatePoint $requestDate): RateSet
    {
        $this->assert($requestDate);
        try {
            return $this->cache->get($requestDate);
        } catch (RateSetNotFound) {
            /*
             * Отсутствие записи в кеше является штатным событием.
             */
        } catch (RateSetCacheFailure $e) {
            $this->logger->warning('Не удалось прочитать кеш курсов', ['exception' => $e]);
        }
        try {
            $rateSet = $this->repository->get($requestDate);
        } catch (RateSetNotFound) {
            /**
             *  Если набора нет в БД идем в API
             *  - Используется эксклюзивная блокировка на ресурс $requestDate для дедубликации запросов в API
             *  - Если что-то пошло не так, под капотом блокировка снимается в блоке finally.
             */
            return $this->loadLock->synchronized(
                $requestDate,
                function (RateSetLoadLockGuard $guard) use ($requestDate): RateSet {
                    /*
                     * Перед запросом повторно проверяем БД: другой воркер
                     * мог сохранить набор до получения блокировки.
                     */
                    try {
                        $rateSet = $this->repository->get($requestDate);
                        $this->logger->debug(
                            'Набор курсов найден в БД после получения блокировки',
                            [
                                'requested_date' => $requestDate->format(DateFormatInterface::ISO),
                            ]
                        );
                    } catch (RateSetNotFound) {
                        /*
                         * Перед внешним запросом продлеваем аренду блокировки,
                         * поскольку источник может ответить с задержкой.
                         */
                        $guard->refresh();
                        $rateSet = $this->gateway->fetch($requestDate);
                        /*
                         * Перед сохранением повторно подтверждаем владение блокировкой.
                         */
                        $guard->refresh();
                        try {
                            $this->repository->save($requestDate, $rateSet);
                        } catch (\Throwable $e) {
                            $this->logger->error('Не удалось сохранить набор курсов', [
                                'requested_date' => $requestDate->format(DateFormatInterface::ISO),
                                'exception_class' => $e::class,
                            ]);
                            throw $e;
                        }
                    }
                    /*
                     * Заполняем кеш только после успешного чтения или сохранения в БД.
                     */
                    $this->warmCache($requestDate, $rateSet);

                    return $rateSet;
                }
            );
        }
        /*
         * Набор, полученный из БД, также прогревает кеш календарной даты.
         */
        $this->warmCache($requestDate, $rateSet);

        return $rateSet;
    }

    private function assert(DatePoint $requestDate): void
    {
        $this->gatewayDatePolicy->assertAllowed($requestDate);
    }

    private function warmCache(DatePoint $requestDate, RateSet $rateSet): void
    {
        try {
            $this->cache->put($requestDate, $rateSet);
        } catch (RateSetCacheFailure $e) {
            $this->logger->warning('Не удалось заполнить кеш курсов', ['exception' => $e]);
        }
    }
}
