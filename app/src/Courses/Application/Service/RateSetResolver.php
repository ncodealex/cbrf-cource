<?php

declare(strict_types=1);

namespace App\Courses\Application\Service;

use App\Courses\Application\Contract\ExternalRateSetGatewayDatePolicyInterface;
use App\Courses\Application\Contract\ExternalRateSetGatewayInterface;
use App\Courses\Application\Contract\RateSetCacheInterface;
use App\Courses\Application\Contract\RateSetLoadLockGuard;
use App\Courses\Application\Contract\RateSetLoadLockInterface;
use App\Courses\Application\Contract\RateSetProviderInterface;
use App\Courses\Application\Contract\RateSetRepositoryInterface;
use App\Courses\Application\Exception\RateSetCacheFailure;
use App\Courses\Application\Exception\RateSetNotFound;
use App\Courses\Domain\Entity\RateSet;
use App\Shared\Domain\DateFormatInterface;
use Closure;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Throwable;

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
     *
     * confirmedOnly обходит Redis: импорт требует сохранённой календарной связи.
     * persist ограждает только запись в БД; внешний запрос выполняется до его вызова.
     *
     * @param Closure(Closure(): void): void|null $persist транзакционная защита сохранения
     */
    public function resolve(
        DatePoint $requestDate,
        ?Closure $persist = null,
        bool $confirmedOnly = false,
    ): RateSet {
        $this->assert($requestDate);
        try {
            if (!$confirmedOnly) {
                return $this->cache->get($requestDate);
            }
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
                function (RateSetLoadLockGuard $guard) use ($requestDate, $persist): RateSet {
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
                            ],
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
                            if (null === $persist) {
                                $this->repository->save($requestDate, $rateSet);
                            } else {
                                $persist(fn() => $this->repository->save($requestDate, $rateSet));
                            }
                        } catch (Throwable $e) {
                            $this->logger->error('Не удалось сохранить набор курсов', [
                                'requested_date'  => $requestDate->format(DateFormatInterface::ISO),
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
                },
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

    private function warmCache(
        DatePoint $requestDate,
        RateSet $rateSet,
    ): void {
        try {
            $this->cache->put($requestDate, $rateSet);
        } catch (RateSetCacheFailure $e) {
            $this->logger->warning('Не удалось заполнить кеш курсов', ['exception' => $e]);
        }
    }
}
