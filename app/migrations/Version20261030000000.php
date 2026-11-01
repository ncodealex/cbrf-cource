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
        return 'Неизменяемые официальные наборы курсов ЦБ и подтверждённые календарные даты';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Для хранения курсов требуется PostgreSQL');
        $this->addSql('CREATE TABLE rate_sets (effective_date DATE PRIMARY KEY, rates JSONB NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())');
        $this->addSql('CREATE TABLE rate_request_dates (requested_date DATE PRIMARY KEY, effective_date DATE NOT NULL REFERENCES rate_sets(effective_date))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rate_request_dates');
        $this->addSql('DROP TABLE rate_sets');
    }
}
