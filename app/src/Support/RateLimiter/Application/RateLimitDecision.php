<?php

declare(strict_types=1);

namespace App\Support\RateLimiter\Application;

use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class RateLimitDecision
{
    public function __construct(public bool $accepted, public DatePoint $retryAt)
    {
    }
}
