<?php

declare(strict_types=1);

return [
    'doctrine' => [
        'dbal' => [
            // Credentials передаются как значения, а не вставляются в URI.
            'driver' => 'pdo_pgsql',
            'host' => '%env(POSTGRES_HOST)%',
            'port' => '%env(int:POSTGRES_PORT)%',
            'dbname' => '%env(POSTGRES_DB)%',
            'user' => '%env(POSTGRES_USER)%',
            'password' => '%env(POSTGRES_PASSWORD)%',
            'server_version' => '18',
            'charset' => 'utf8',
            // Таблицами Cources и Import управляют DBAL и миграции, а не ORM SchemaTool.
            'schema_filter' => '~^(?!(?:rate_sets|rate_request_dates|rate_import_jobs)$)~',
            'profiling_collect_backtrace' => '%kernel.debug%',
            'options' => [
                // pdo_pgsql передаёт ATTR_TIMEOUT в libpq как connect_timeout (секунды).
                PDO::ATTR_TIMEOUT => 2,
            ],
        ],
        'orm' => [
            'validate_xml_mapping' => true,
            'naming_strategy' => 'doctrine.orm.naming_strategy.underscore',
            'identity_generation_preferences' => [
                Doctrine\DBAL\Platforms\PostgreSQLPlatform::class => 'identity',
            ],
            'auto_mapping' => true,
            // Маппинг сущностей добавляет каждый бизнес-модуль, например:
            // 'Billing' => ['type' => 'attribute', 'is_bundle' => false,
            //     'dir' => '%kernel.project_dir%/src/Billing/Domain', 'prefix' => 'App\Billing\Domain'],
            'mappings' => [],
        ],
    ],
    'doctrine_migrations' => [
        'migrations_paths' => [
            'DoctrineMigrations' => '%kernel.project_dir%/migrations',
        ],
        'enable_profiler' => false,
    ],
    'when@test' => [
        'doctrine' => [
            'dbal' => [
                'dbname_suffix' => '_test',
            ],
        ],
    ],
    'when@prod' => [
        'doctrine' => [
            'orm' => [
                'metadata_cache_driver' => ['type' => 'pool', 'pool' => 'doctrine.system_cache_pool'],
                'query_cache_driver' => ['type' => 'pool', 'pool' => 'doctrine.system_cache_pool'],
                'result_cache_driver' => ['type' => 'pool', 'pool' => 'doctrine.result_cache_pool'],
            ],
        ],
        'framework' => [
            'cache' => [
                'pools' => [
                    'doctrine.result_cache_pool' => ['adapter' => 'cache.app'],
                    'doctrine.system_cache_pool' => ['adapter' => 'cache.system'],
                ],
            ],
        ],
    ],
];
