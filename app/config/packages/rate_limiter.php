<?php

declare(strict_types=1);

use App\Support\RateLimiter\Infrastructure\RedisRateLimiterStorage;

return [
    'framework' => [
        'rate_limiter' => [
            'cbr' => [
                'policy' => 'token_bucket',
                'limit' => 2,
                'rate' => ['interval' => '3 seconds', 'amount' => 1],
                'lock_factory' => 'app.cbr.lock_factory',
                'storage_service' => RedisRateLimiterStorage::class,
            ],
        ],
    ],
];
