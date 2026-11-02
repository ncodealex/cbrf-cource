<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Индексы доступности заданий, истечения аренды и календарных связей наборов курсов';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Для индексов заданий импорта требуется PostgreSQL');
        $this->addSql("CREATE INDEX idx_rate_import_jobs_ready ON rate_import_jobs (available_at, requested_date) WHERE status IN ('waiting', 'deferred')");
        $this->addSql("CREATE INDEX idx_rate_import_jobs_expired_lease ON rate_import_jobs (lease_until, requested_date) WHERE status = 'processing'");
        $this->addSql('CREATE INDEX idx_rate_request_dates_effective ON rate_request_dates (effective_date, requested_date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_rate_import_jobs_ready');
        $this->addSql('DROP INDEX idx_rate_import_jobs_expired_lease');
        $this->addSql('DROP INDEX idx_rate_request_dates_effective');
    }
}
