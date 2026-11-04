<?php

declare(strict_types=1);

namespace App\Tests\Integration\Import;

use App\Import\Infrastructure\Persistence\DoctrineImportJobQueryRepository;
use App\Import\Infrastructure\Persistence\DoctrineImportJobRepository;
use App\Tests\Integration\Cources\RateSetStorageTestCase;
use Doctrine\DBAL\DriverManager;

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
        $this->jobs = new DoctrineImportJobRepository($this->connection);
        $this->queries = new DoctrineImportJobQueryRepository($this->connection);
    }
}
