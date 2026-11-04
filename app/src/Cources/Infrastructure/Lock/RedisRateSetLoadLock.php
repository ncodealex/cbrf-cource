<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Lock;

use App\Cources\Application\Contract\RateSetLoadLockInterface;
use App\Cources\Application\Exception\RateSetLoadBusy;
use App\Cources\Application\Exception\RateSetLoadLockFailure;
use App\Cources\Domain\Entity\RateSet;
use App\Shared\Domain\DateFormatInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

#[AsAlias(RateSetLoadLockInterface::class)]
final readonly class RedisRateSetLoadLock implements RateSetLoadLockInterface
{
    public function __construct(
        #[Autowire(service: 'app.cbr.lock_factory')]
        private LockFactory $factory,
        private LoggerInterface $logger,
        #[Autowire(param: 'app.cbr.load_lock_ttl')]
        private float $ttl,
    ) {
        if ($ttl <= 15.0) {
            throw new \InvalidArgumentException('TTL блокировки должен превышать максимальную длительность HTTP-запроса (15 секунд).');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function synchronized(
        DatePoint $requestedDate,
        callable $operation,
    ): RateSet {
        $key = 'cbr:rateset:load:'.$requestedDate->format(DateFormatInterface::ISO);
        $context = ['requested_date' => $requestedDate->format(DateFormatInterface::ISO), 'lock_key' => $key];
        $lock = $this->factory->createLock($key, $this->ttl, false);
        try {
            $acquired = $lock->acquire(false);
        } catch (\Throwable $e) {
            try {
                $lock->release();
            } catch (\Throwable $releaseFailure) {
                $this->logger->warning(
                    'Не удалось очистить блокировку после сбоя захвата',
                    $context + ['exception_class' => $releaseFailure::class],
                );
            }
            $this->logger->warning('Сбой блокировки загрузки курсов', $context + ['exception_class' => $e::class]);
            throw new RateSetLoadLockFailure(previous: $e);
        }
        if (!$acquired) {
            $this->logger->debug('Загрузка курсов уже заблокирована', $context);
            throw new RateSetLoadBusy();
        }
        $this->logger->debug('Блокировка загрузки курсов получена', $context);
        $guard = new RedisRateSetLoadLockGuard($lock, $this->ttl, $this->logger, $context);
        $failure = null;
        try {
            $result = $operation($guard);
            $guard->refresh();

            return $result;
        } catch (\Throwable $e) {
            $failure = $e;
            throw $e;
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Не удалось освободить блокировку загрузки курсов',
                    $context + ['exception_class' => $e::class],
                );
                if (null === $failure) {
                    throw new RateSetLoadLockFailure(previous: $e);
                }
            }
        }
    }
}
