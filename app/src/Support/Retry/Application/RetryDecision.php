<?php

declare(strict_types=1);

namespace App\Support\Retry\Application;

use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class RetryDecision
{
    public function __construct(
        public bool $allowed,
        public ?DatePoint $retryAt,
    ) {
    }
}
