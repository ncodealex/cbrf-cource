<?php

declare(strict_types=1);

use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Clock\Clock;

/*
 * Сервисы регистрируются автоматически (autowire + autoconfigure) и настраиваются
 * PHP-атрибутами в самих классах. Здесь — только то, что нельзя выразить атрибутами:
 * resource/exclude, параметры и сервисы, создаваемые фабриками.
 */
return [
    'parameters' => [
        'app.cbr.load_lock_ttl'        => 30.0,
        'app.redis.connection_options' => [
            'lazy'         => true,
            'timeout'      => 1.0,
            'read_timeout' => 1.0,
        ],
        // Три probe по 2s: readiness < 7s с учётом завершения процессов.
        'app.health.probe_timeout'     => 2.0,
    ],
    'services'   => [
        '_defaults' => [
            'autowire'      => true,
            'autoconfigure' => true,
        ],

        'App\\' => [
            'resource' => '../src/',
            'exclude'  => [
                '../src/Kernel.php',
                '../src/Cources/Domain/',
                '../src/Cources/Infrastructure/Cbr/Response/',
                '../src/Cources/Application/Exception/',
                '../src/Cources/Infrastructure/Mapping/InvalidRateSetPayload.php',
                '../src/Modules/*/Domain/',
                '../src/Integration/*/Domain/',
            ],
        ],

        App\Cources\Domain\ExchangeRateCalculator::class => [],
        'app.cbr.console_retry_policy'                   => [
            'class'     => App\Support\Retry\Application\RetryPolicy::class,
            'arguments' => ['@' . Psr\Clock\ClockInterface::class, 3, 100, 200, 20],
        ],
        'app.cbr.lock_store'                             => [
            'class'     => Symfony\Component\Lock\Store\RedisStore::class,
            'arguments' => ['@' . Redis::class],
        ],
        'app.cbr.lock_factory'                           => [
            'class'     => Symfony\Component\Lock\LockFactory::class,
            'arguments' => ['@app.cbr.lock_store'],
        ],

        // Штатный ClockInterface от FrameworkBundle, зафиксированный в часовой зоне приложения.
        // Clock оставляем обёрткой над глобальными часами для поддержки MockClock в тестах.
        'app.timezone'                                   => [
            'class'     => DateTimeZone::class,
            'arguments' => ['%env(TZ)%'],
        ],
        'clock'                                          => [
            'class'     => Clock::class,
            'arguments' => [null, '@app.timezone'],
        ],

        // Redis backend для Symfony Cache; probe использует те же DSN/options.
        // lazy — подключение устанавливается при первом обращении, а не при сборке сервиса.
        Redis::class                                     => [
            'class'     => Redis::class,
            'factory'   => [RedisAdapter::class, 'createConnection'],
            'arguments' => [
                '%env(REDIS_URL)%',
                '%app.redis.connection_options%',
            ],
        ],
    ],
    'when@test'  => [
        'services' => [
            'test.rateset_resolver' => [
                'alias'  => App\Cources\Application\Service\RateSetResolver::class,
                'public' => true,
            ],
        ],
    ],
    'when@prod'  => [
        'parameters' => [
            '.container.dumper.inline_factories' => true,
        ],
    ],
];
