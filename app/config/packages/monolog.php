<?php

declare(strict_types=1);

return [
    'parameters' => [
        'app.logging.default_level' => 'info',
        'app.logging.default_stderr_level' => 'info',
    ],
    'monolog' => [
        'channels' => ['deprecation', 'import_dispatcher', 'import_worker', 'cbrf'],
        'handlers' => [
            'main' => [
                'type' => 'rotating_file',
                'use_locking' => true,
                'path' => '%kernel.logs_dir%/main.log',
                'max_files' => 14,
                'level' => '%env(default:app.logging.default_level:APP_LOG_LEVEL)%',
                'formatter' => 'monolog.formatter.json',
            ],
            'errors' => [
                'type' => 'rotating_file',
                'use_locking' => true,
                'path' => '%kernel.logs_dir%/error.log',
                'max_files' => 30,
                'level' => 'error',
                'formatter' => 'monolog.formatter.json',
            ],
            'cbrf' => [
                'type' => 'rotating_file',
                'use_locking' => true,
                'path' => '%kernel.logs_dir%/cbrf.log',
                'max_files' => 14,
                'level' => 'debug',
                'channels' => ['cbrf'],
                'formatter' => 'monolog.formatter.json',
            ],
            'stderr' => [
                'type' => 'stream',
                'path' => 'php://stderr',
                'level' => '%env(default:app.logging.default_stderr_level:APP_STDERR_LOG_LEVEL)%',
                'formatter' => 'monolog.formatter.json',
            ],
        ],
    ],
    'when@dev' => [
        'parameters' => [
            'app.logging.default_level' => 'debug',
            'app.logging.default_stderr_level' => 'warning',
        ],
    ],
    'when@test' => [
        'parameters' => [
            'app.logging.default_level' => 'error',
            'app.logging.default_stderr_level' => 'error',
        ],
    ],
];
