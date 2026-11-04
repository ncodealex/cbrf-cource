<?php

declare(strict_types=1);

return [
    'parameters' => [
        'app.cache.default_ttl' => '3600',
    ],
    'framework' => [
        'cache' => [
            'app' => 'cache.adapter.redis',
            'default_redis_provider' => Redis::class,
        ],
    ],
];
