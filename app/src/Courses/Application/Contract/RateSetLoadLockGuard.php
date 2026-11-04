<?php

declare(strict_types=1);

namespace App\Courses\Application\Contract;

use App\Courses\Application\Exception\RateSetLoadLockFailure;

interface RateSetLoadLockGuard
{
    /**
     * Проверить владение и продлить аренду.
     *
     * @throws RateSetLoadLockFailure
     */
    public function refresh(): void;
}
