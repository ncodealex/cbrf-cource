<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Каскадное удаление календарных дат вместе с набором курсов';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Для хранения курсов требуется PostgreSQL');
        $this->addSql(
            <<<'SQL'
                ALTER TABLE
                    rate_request_dates
                DROP CONSTRAINT rate_request_dates_effective_date_fkey
                SQL,
        );
        $this->addSql(
            <<<'SQL'
                ALTER TABLE
                    rate_request_dates
                ADD
                    CONSTRAINT rate_request_dates_effective_date_fkey FOREIGN KEY (effective_date) REFERENCES rate_sets (effective_date) ON DELETE CASCADE
                SQL,
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            <<<'SQL'
                ALTER TABLE
                    rate_request_dates
                DROP CONSTRAINT rate_request_dates_effective_date_fkey
                SQL,
        );
        $this->addSql(
            <<<'SQL'
                ALTER TABLE
                    rate_request_dates
                ADD
                    CONSTRAINT rate_request_dates_effective_date_fkey FOREIGN KEY (effective_date) REFERENCES rate_sets (effective_date)
                SQL,
        );
    }
}
