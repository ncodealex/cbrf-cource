<?php

declare(strict_types=1);

namespace App\Support\Health\Infrastructure;

use App\Support\Health\Application\HealthProbe;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class RedisHealthProbe implements HealthProbe
{
    /** @param array<string, mixed> $connectionOptions */
    public function __construct(
        #[Autowire(env: 'REDIS_URL')]
        private string $dsn,
        #[Autowire('%app.redis.connection_options%')]
        private array $connectionOptions,
        private ProbeProcess $process,
    ) {
    }

    public function name(): string
    {
        return 'redis';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function check(): void
    {
        $this->process->check('redis', [
            'dsn' => $this->dsn,
            'options' => $this->connectionOptions,
        ]);
    }
}
