<?php

declare(strict_types=1);

return [
    'parameters' => ['env(APP_LOG_DIR)' => '%kernel.logs_dir%', 'env(APP_LOG_LEVEL)' => 'info', 'env(APP_STDERR_LOG_LEVEL)' => 'info'],
    'monolog' => [
        'channels' => ['deprecation', 'import_dispatcher', 'import_worker', 'cbrf'],
        'handlers' => [
            'main' => ['type' => 'rotating_file', 'path' => '%env(resolve:APP_LOG_DIR)%/main.log', 'max_files' => 14, 'level' => '%env(APP_LOG_LEVEL)%', 'formatter' => 'monolog.formatter.json'],
            'errors' => ['type' => 'rotating_file', 'path' => '%env(resolve:APP_LOG_DIR)%/error.log', 'max_files' => 30, 'level' => 'error', 'formatter' => 'monolog.formatter.json'],
            'cbrf' => ['type' => 'rotating_file', 'path' => '%env(resolve:APP_LOG_DIR)%/cbrf.log', 'max_files' => 14, 'level' => 'debug', 'channels' => ['cbrf'], 'formatter' => 'monolog.formatter.json'],
            'stderr' => ['type' => 'stream', 'path' => 'php://stderr', 'level' => '%env(APP_STDERR_LOG_LEVEL)%', 'formatter' => 'monolog.formatter.json'],
        ]],
    'when@dev' => ['parameters' => ['env(APP_LOG_LEVEL)' => 'debug', 'env(APP_STDERR_LOG_LEVEL)' => 'warning']],
    'when@test' => ['parameters' => ['env(APP_LOG_LEVEL)' => 'error', 'env(APP_STDERR_LOG_LEVEL)' => 'error']],
];
