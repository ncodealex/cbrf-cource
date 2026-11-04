<?php

declare(strict_types=1);

namespace App\Courses\Application\Contract;

use App\Courses\Application\Exception\RateSetLoadBusy;
use App\Courses\Application\Exception\RateSetLoadLockFailure;
use App\Courses\Domain\Entity\RateSet;
use Symfony\Component\Clock\DatePoint;

interface RateSetLoadLockInterface
{
    /**
     * Немедленно захватить эксклюзивную блокировку, без ожидания.
     *
     * Выполнить operation под арендой календарной даты и освободить её также при исключении.
     * Вернуть результат operation; исключения самой операции передать вызывающему.
     *
     * @param callable(RateSetLoadLockGuard): RateSet $operation получение и сохранение набора
     *
     * @throws RateSetLoadBusy
     * @throws RateSetLoadLockFailure
     */
    public function synchronized(
        DatePoint $requestedDate,
        callable $operation,
    ): RateSet;
}
