<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Регистрация заданий импорта котировок по календарным датам';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Для хранения заданий импорта требуется PostgreSQL');
        $this->addSql(<<<'SQL'
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
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rate_import_jobs');
    }
}
