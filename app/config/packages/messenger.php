<?php

declare(strict_types=1);

$connectionOptions = [
    'login' => '%env(RABBITMQ_USER)%',
    'password' => '%env(RABBITMQ_PASSWORD)%',
    'connect_timeout' => 1.0,
    'read_timeout' => 1.0,
    'write_timeout' => 1.0,
    'rpc_timeout' => 1.0,
    'confirm_timeout' => 1.0,
    'heartbeat' => 15,
];

return [
    'parameters' => [
        // Те же credentials/TLS/таймауты использует readiness probe.
        'app.amqp.connection_options' => $connectionOptions,
    ],
    'framework' => [
        'messenger' => [
            'failure_transport' => 'failed',
            'transports' => [
                'rates_import' => [
                    'dsn' => '%env(MESSENGER_TRANSPORT_DSN)%',
                    'options' => [
                        ...$connectionOptions,
                        'exchange' => [
                            'name' => 'rates_import',
                            'type' => 'direct',
                            'flags' => 2,
                            'default_publish_routing_key' => 'rates_import',
                        ],
                        'queues' => [
                            'rates_import' => [
                                'flags' => 2,
                                'binding_keys' => ['rates_import'],
                            ],
                        ],
                    ],
                    'retry_strategy' => [
                        'max_retries' => 3,
                        'delay' => 1000,
                        'multiplier' => 3,
                        'max_delay' => 60000,
                    ],
                ],
                'failed' => [
                    'dsn' => '%env(MESSENGER_TRANSPORT_DSN)%',
                    'options' => [
                        ...$connectionOptions,
                        'exchange' => [
                            'name' => 'failed',
                            'type' => 'direct',
                            'default_publish_routing_key' => 'failed',
                        ],
                        // Messenger сохраняет routing key исходного AMQP-сообщения при отправке в failure transport.
                        'queues' => [
                            'failed' => [
                                'binding_keys' => ['failed', 'rates_import'],
                            ],
                        ],
                    ],
                ],
            ],
            'routing' => [
                App\Import\Application\Message\ProcessRateImportMessage::class => 'rates_import',
            ],
        ],
    ],
];
