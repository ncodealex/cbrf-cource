<?php

declare(strict_types=1);

/*
 * cache.app — общий Redis backend для всех экземпляров приложения.
 * cache.system остаётся локальным (файловым): он содержит артефакты сборки конкретного образа.
 *
 * Новые пулы добавляются в 'pools' с собственным TTL (default_lifetime, секунды), например:
 *     'cache.some_module' => ['adapter' => 'cache.app', 'default_lifetime' => 600],
 */
return [
    'framework' => [
        'cache' => [
            'app' => 'cache.adapter.redis',
            'default_redis_provider' => Redis::class,
            'pools' => [
                'cache.shared' => [
                    'adapter' => 'cache.app',
                    'default_lifetime' => '%env(int:CACHE_DEFAULT_TTL)%',
                ],
            ],
        ],
    ],
];
