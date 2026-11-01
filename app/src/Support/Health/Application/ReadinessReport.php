<?php

declare(strict_types=1);

namespace App\Support\Health\Application;

final readonly class ReadinessReport
{
    public const string OK = 'ok';
    public const string FAILED = 'failed';

    /**
     * @param array<string, self::OK|self::FAILED> $checks
     */
    public function __construct(
        public array $checks,
    ) {
    }

    public function isReady(): bool
    {
        return !\in_array(self::FAILED, $this->checks, true);
    }
}
