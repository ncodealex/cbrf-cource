<?php

declare(strict_types=1);

namespace App\Courses\Infrastructure\Lock;

use App\Courses\Application\Contract\RateSetLoadLockGuard;
use App\Courses\Application\Exception\RateSetLoadLockFailure;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Lock\LockInterface;
use Throwable;

#[Exclude]
final readonly class RedisRateSetLoadLockGuard implements RateSetLoadLockGuard
{
    /**
     * @param array<string, string> $context контекст журнала для потери владения
     */
    public function __construct(
        private LockInterface $lock,
        private float $ttl,
        private LoggerInterface $logger,
        private array $context,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function refresh(): void
    {
        try {
            if ($this->lock->isExpired() || !$this->lock->isAcquired()) {
                throw new RuntimeException('Владение блокировкой загрузки курсов потеряно.');
            }
            $this->lock->refresh($this->ttl);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Потеряно или не подтверждено владение блокировкой',
                $this->context + ['exception_class' => $e::class],
            );
            throw new RateSetLoadLockFailure(previous: $e);
        }
    }
}
