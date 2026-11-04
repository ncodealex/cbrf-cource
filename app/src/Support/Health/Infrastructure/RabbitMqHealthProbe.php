<?php

declare(strict_types=1);

namespace App\Support\Health\Infrastructure;

use App\Support\Health\Application\HealthProbe;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Открывает и закрывает AMQP-соединение с брокером Messenger. Включается HEALTH_CHECK_RABBITMQ=1.
 */
final readonly class RabbitMqHealthProbe implements HealthProbe
{
    /**
     * @param array<string, mixed> $connectionOptions
     */
    public function __construct(
        #[Autowire(env: 'MESSENGER_TRANSPORT_DSN')]
        private string $dsn,
        #[Autowire(env: 'bool:HEALTH_CHECK_RABBITMQ')]
        private bool $enabled,
        #[Autowire('%app.amqp.connection_options%')]
        private array $connectionOptions,
        private ProbeProcess $process,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return 'rabbitmq';
    }

    /**
     * {@inheritdoc}
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * {@inheritdoc}
     */
    public function check(): void
    {
        $this->process->check('rabbitmq', [
            'dsn' => $this->dsn,
            'options' => $this->connectionOptions,
        ]);
    }
}
