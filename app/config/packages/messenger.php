<?php

declare(strict_types=1);

/*
 * async  — основной транспорт (RabbitMQ). Exchange и очередь durable, сообщения persistent,
 *          ack отправляется только после успешной обработки.
 * failed — durable-очередь для сообщений, исчерпавших retry (messenger:failed:show / retry).
 *
 * Сообщения маршрутизируются в 'routing', например:
 *     App\Modules\Billing\Application\Message\SendInvoice::class => 'async',
 */
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
                'async' => [
                    'dsn' => '%env(MESSENGER_TRANSPORT_DSN)%',
                    'options' => [
                        ...$connectionOptions,
                        'exchange' => ['name' => 'messages', 'type' => 'direct', 'default_publish_routing_key' => 'messages'],
                        'queues' => ['messages' => ['binding_keys' => ['messages']]],
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
                        'exchange' => ['name' => 'failed', 'type' => 'direct', 'default_publish_routing_key' => 'failed'],
                        'queues' => ['failed' => ['binding_keys' => ['failed']]],
                    ],
                ],
            ],
            'routing' => [],
        ],
    ],
];
