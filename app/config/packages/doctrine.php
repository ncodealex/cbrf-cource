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
            'options' => [
                // pdo_pgsql передаёт ATTR_TIMEOUT в libpq как connect_timeout (секунды).
                PDO::ATTR_TIMEOUT => 2,
            ],
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
];
