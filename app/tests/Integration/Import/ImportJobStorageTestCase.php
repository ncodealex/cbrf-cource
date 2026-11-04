<?php

declare(strict_types=1);

namespace App\Tests\Integration\Import;

use App\Import\Infrastructure\Persistence\DoctrineImportJobQueryRepository;
use App\Import\Infrastructure\Persistence\DoctrineImportJobRepository;
use App\Tests\Integration\Cources\RateSetStorageTestCase;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;

/**
 * @phpstan-import-type Params from DriverManager
 */
abstract class ImportJobStorageTestCase extends RateSetStorageTestCase
{
    protected DoctrineImportJobRepository $jobs;
    protected DoctrineImportJobQueryRepository $queries;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Version20261101203000', 'Version20261102120000', 'Version20261102150000'] as $name) {
            require_once \dirname(__DIR__, 3).'/migrations/'.$name.'.php';
            $class = 'DoctrineMigrations\\'.$name;
            $migration = new $class($this->connection, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $this->connection->executeStatement($query->getStatement());
            }
        }
        $this->jobs = new DoctrineImportJobRepository($this->connection);
        $this->queries = new DoctrineImportJobQueryRepository($this->connection);
    }
}
