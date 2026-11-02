<?php

declare(strict_types=1);

namespace App\Support\RateLimiter\Application;

interface RequestRateLimiter
{
    /**
     * Неблокирующая проверка; поддерживается только maxWaitSeconds = 0.
     *
     * @throws RequestRateLimiterFailure
     */
    public function acquire(): RateLimitDecision;
}
