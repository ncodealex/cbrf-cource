<?php

declare(strict_types=1);

namespace App\Support\RateLimiter\Application;

interface RequestRateLimiter
{
    /** Wait at most maxWaitSeconds; zero means non-blocking. */
    public function acquire(float $maxWaitSeconds = 0): RateLimitDecision;
}
