<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Integration\Cources\RateSetStorageTestCase;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261101000000;
use Psr\Log\NullLogger;

final class ConsolidatedMigrationTest extends RateSetStorageTestCase
{
    public function testFullSchemaCanBeRolledBackAndCreatedAgain(): void
    {
        $manager = $this->connection->createSchemaManager();
        self::assertTrue($manager->tablesExist(['rate_sets', 'rate_request_dates', 'rate_import_jobs']));
        $indexes = $manager->listTableIndexes('rate_import_jobs');
        self::assertArrayHasKey('idx_rate_import_jobs_ready', $indexes);
        self::assertArrayHasKey('idx_rate_import_jobs_expired_lease', $indexes);
        self::assertArrayHasKey('idx_rate_request_dates_effective', $manager->listTableIndexes('rate_request_dates'));

        $migration = new Version20261101000000($this->connection, new NullLogger());
        $migration->down(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
        $remaining = $this->connection->executeQuery(
            <<<'SQL'
                SELECT table_name
                FROM information_schema.tables
                WHERE table_schema = :schema
                SQL,
            ['schema' => $this->schema],
        );
        self::assertSame([], $remaining->fetchFirstColumn());

        $migration = new Version20261101000000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
        self::assertTrue($manager->tablesExist(['rate_sets', 'rate_request_dates', 'rate_import_jobs']));
    }
}
