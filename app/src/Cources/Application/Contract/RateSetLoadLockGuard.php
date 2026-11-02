<?php

declare(strict_types=1);

namespace App\Cources\Application\Contract;

use App\Cources\Application\Exception\RateSetLoadLockFailure;

interface RateSetLoadLockGuard
{
    /**
     * Проверить владение и продлить аренду.
     *
     * @throws RateSetLoadLockFailure
     */
    public function refresh(): void;
}
