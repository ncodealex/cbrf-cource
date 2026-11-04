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
        'app.redis.default_url' => 'redis://redis:6379',

        'app.cbr.load_lock_ttl' => 30.0,
        'app.redis.connection_options' => [
            'lazy' => true,
            'timeout' => 1.0,
            'read_timeout' => 1.0,
        ],
    ],
    'services' => [
        '_defaults' => [
            'autowire' => true,
            'autoconfigure' => true,
        ],

        'App\\' => [
            'resource' => '../src/',
            'exclude' => [
                '../src/Kernel.php',
                '../src/Cources/Domain/',
                '../src/Import/Domain/',
                '../src/Cources/Infrastructure/Cbr/Response/',
                '../src/Cources/Application/Exception/',
                '../src/Cources/Infrastructure/Mapping/InvalidRateSetPayload.php',
            ],
        ],

        App\Cources\Domain\ExchangeRateCalculator::class => [],

        'app.import.retry_policy' => [
            'class' => App\Support\Retry\Application\RetryPolicy::class,
            'arguments' => [
                '@'.Psr\Clock\ClockInterface::class,
                '%env(int:default:app.import.default_max_attempts:IMPORT_MAX_ATTEMPTS)%',
                '%env(int:default:app.import.default_retry_initial_delay_ms:IMPORT_RETRY_INITIAL_DELAY_MS)%',
                '%env(int:default:app.import.default_retry_max_delay_ms:IMPORT_RETRY_MAX_DELAY_MS)%',
                '%env(int:default:app.import.default_retry_jitter_percent:IMPORT_RETRY_JITTER_PERCENT)%',
            ],
        ],
        'app.cbr.console_retry_policy' => [
            'class' => App\Support\Retry\Application\RetryPolicy::class,
            'arguments' => [
                '@'.Psr\Clock\ClockInterface::class,
                3,
                100,
                200,
                20,
            ],
        ],
        'app.cbr.lock_store' => [
            'class' => Symfony\Component\Lock\Store\RedisStore::class,
            'arguments' => ['@'.Redis::class],
        ],
        'app.cbr.lock_factory' => [
            'class' => Symfony\Component\Lock\LockFactory::class,
            'arguments' => ['@app.cbr.lock_store'],
        ],

        // Штатный ClockInterface от FrameworkBundle, зафиксированный в часовой зоне приложения.
        // Clock оставляем обёрткой над глобальными часами для поддержки MockClock в тестах.
        'app.timezone' => [
            'class' => DateTimeZone::class,
            'arguments' => ['%env(default:app.default_timezone:TZ)%'],
        ],
        'clock' => [
            'class' => Clock::class,
            'arguments' => [null, '@app.timezone'],
        ],

        // Redis backend для Symfony Cache; probe использует те же DSN/options.
        // lazy — подключение устанавливается при первом обращении, а не при сборке сервиса.
        Redis::class => [
            'class' => Redis::class,
            'factory' => [RedisAdapter::class, 'createConnection'],
            'arguments' => [
                '%env(default:app.redis.default_url:REDIS_URL)%',
                '%app.redis.connection_options%',
            ],
        ],
    ],
    'when@test' => [
        'services' => [
            'test.rateset_resolver' => [
                'alias' => App\Cources\Application\Service\RateSetResolver::class,
                'public' => true,
            ],
        ],
    ],
    'when@prod' => [
        'parameters' => [
            '.container.dumper.inline_factories' => true,
        ],
    ],
];
