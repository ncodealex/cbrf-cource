<?php

declare(strict_types=1);

/*
 * Логи пишутся только в stderr контейнера (docker compose logs).
 * dev — читаемый формат, prod — JSON уровня INFO и выше.
 */
return [
    'monolog' => [
        'channels' => ['deprecation'],
    ],
    'when@dev' => [
        'monolog' => [
            'handlers' => [
                'main' => [
                    'type' => 'stream',
                    'path' => 'php://stderr',
                    'level' => 'debug',
                    'channels' => ['!event', '!deprecation', '!doctrine'],
                ],
            ],
        ],
    ],
    'when@test' => [
        'monolog' => [
            'handlers' => [
                'main' => [
                    'type' => 'fingers_crossed',
                    'action_level' => 'error',
                    'handler' => 'nested',
                    'excluded_http_codes' => [404, 405],
                    'channels' => ['!event'],
                ],
                'nested' => [
                    'type' => 'stream',
                    'path' => '%kernel.logs_dir%/%kernel.environment%.log',
                    'level' => 'debug',
                ],
            ],
        ],
    ],
    'when@prod' => [
        'monolog' => [
            'handlers' => [
                'main' => [
                    'type' => 'stream',
                    'path' => 'php://stderr',
                    'level' => 'info',
                    'formatter' => 'monolog.formatter.json',
                    'channels' => ['!event', '!deprecation', '!doctrine'],
                ],
            ],
        ],
    ],
];
