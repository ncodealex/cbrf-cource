<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Наборы курсов ЦБ, календарные связи и задания импорта с индексами';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Для хранения курсов требуется PostgreSQL');
        $this->addSql(
            <<<'SQL'
                CREATE TABLE rate_sets (
                    effective_date DATE PRIMARY KEY,
                    rates JSONB NOT NULL,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )
                SQL,
        );
        $this->addSql(
            <<<'SQL'
                CREATE TABLE rate_request_dates (
                    requested_date DATE PRIMARY KEY,
                    effective_date DATE NOT NULL REFERENCES rate_sets(effective_date) ON DELETE CASCADE
                )
                SQL,
        );
        $this->addSql(
            <<<'SQL'
                CREATE TABLE rate_import_jobs (
                    requested_date DATE PRIMARY KEY,
                    status VARCHAR(20) NOT NULL DEFAULT 'waiting',
                    version BIGINT NOT NULL DEFAULT 0,
                    attempts INTEGER NOT NULL DEFAULT 0,
                    available_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    lease_until TIMESTAMPTZ,
                    dispatched_at TIMESTAMPTZ,
                    error_text TEXT,
                    error_code UUID,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    completed_at TIMESTAMPTZ
                )
                SQL,
        );
        $this->addSql(
            <<<'SQL'
                CREATE INDEX idx_rate_import_jobs_ready ON rate_import_jobs (available_at, requested_date)
                WHERE status IN ('waiting', 'deferred')
                SQL,
        );
        $this->addSql(
            <<<'SQL'
                CREATE INDEX idx_rate_import_jobs_expired_lease ON rate_import_jobs (lease_until, requested_date)
                WHERE status = 'processing'
                SQL,
        );
        $this->addSql(
            <<<'SQL'
                CREATE INDEX idx_rate_request_dates_effective ON rate_request_dates (effective_date, requested_date)
                SQL,
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            <<<'SQL'
                DROP TABLE rate_import_jobs
                SQL,
        );
        $this->addSql(
            <<<'SQL'
                DROP TABLE rate_request_dates
                SQL,
        );
        $this->addSql(
            <<<'SQL'
                DROP TABLE rate_sets
                SQL,
        );
    }
}
