<?php

declare(strict_types=1);

namespace App\Cources\Application\Contract;

use App\Cources\Application\Exception\RateSetLoadBusy;
use App\Cources\Application\Exception\RateSetLoadLockFailure;
use App\Cources\Domain\Entity\RateSet;
use Symfony\Component\Clock\DatePoint;

interface RateSetLoadLockInterface
{
    /**
     * Немедленно захватить эксклюзивную блокировку, без ожидания.
     *
     * @param callable(RateSetLoadLockGuard): RateSet $operation
     *
     * @throws RateSetLoadBusy
     * @throws RateSetLoadLockFailure
     */
    public function synchronized(DatePoint $requestedDate, callable $operation): RateSet;
}
