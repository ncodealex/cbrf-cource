<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cources;

use App\Cources\Infrastructure\Mapping\RateSetMapper;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261030000000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** @phpstan-import-type Params from DriverManager */
abstract class RateSetStorageTestCase extends KernelTestCase
{
    protected Connection $connection;
    protected RateSetMapper $mapper;
    protected string $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = 'rateset_test_'.bin2hex(random_bytes(8));
        /** @var Params $params параметры существующего PostgreSQL-подключения */
        $params = self::getContainer()->get(Connection::class)->getParams();
        $this->connection = DriverManager::getConnection($params);
        $this->connection->executeStatement('CREATE SCHEMA '.$this->schema);
        $this->connection->executeStatement('SET search_path TO '.$this->schema);
        require_once \dirname(__DIR__, 3).'/migrations/Version20261030000000.php';
        $migration = new Version20261030000000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            self::assertSame([], $query->getParameters());
            self::assertSame([], $query->getTypes());
            $this->connection->executeStatement($query->getStatement());
        }
        $this->mapper = new RateSetMapper();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        while ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        // Удаляется только созданная этим тестом изолированная схема.
        $this->connection->executeStatement('DROP SCHEMA '.$this->schema.' CASCADE');
        $this->connection->close();
    }
}
