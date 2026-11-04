<?php

declare(strict_types=1);

namespace App\Support\Health\Infrastructure;

use App\Support\Health\Application\HealthProbe;
use Doctrine\DBAL\Connection;

final readonly class PostgresHealthProbe implements HealthProbe
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return 'postgres';
    }

    /**
     * {@inheritdoc}
     */
    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function check(): void
    {
        $result = $this->connection->executeQuery(
            <<<'SQL'
                SELECT 1
                SQL,
        );
        $value = $result->fetchOne();
        if (1 !== $value && '1' !== $value) {
            throw new \RuntimeException('PostgreSQL не подтвердил готовность.');
        }
    }
}
