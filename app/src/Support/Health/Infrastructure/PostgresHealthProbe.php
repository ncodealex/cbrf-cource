<?php

declare(strict_types=1);

namespace App\Support\Health\Infrastructure;

use App\Support\Health\Application\HealthProbe;
use Doctrine\DBAL\Connection;

final readonly class PostgresHealthProbe implements HealthProbe
{
    public function __construct(
        private Connection $connection,
        private ProbeProcess $process,
    ) {
    }

    public function name(): string
    {
        return 'postgres';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function check(): void
    {
        /** @var array<string, mixed> $parameters параметры подключения DBAL */
        $parameters = $this->connection->getParams();
        $this->process->check('postgres', $parameters);
    }
}
