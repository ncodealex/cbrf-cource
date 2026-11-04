<?php

declare(strict_types=1);

namespace App\Tests\Integration\Import;

use App\Import\Domain\ValueObject\RatesImportPeriod;
use App\Shared\Domain\DatePointParser;
use Doctrine\DBAL\DriverManager;

/**
 * @phpstan-import-type Params from DriverManager
 */
final class DoctrineImportJobQueryRepositoryTest extends ImportJobStorageTestCase
{
    public function testProgressDoesNotCountUnconfirmedDone(): void
    {
        $date = DatePointParser::parse('2026-01-01');
        $this->jobs->register([$date]);
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE rate_import_jobs
                SET status = 'done'
                SQL,
        );
        $period = new RatesImportPeriod($date, DatePointParser::parse('2026-01-02'));
        $snapshot = $this->queries->progress($period);
        self::assertSame(0, $snapshot->confirmed);
        self::assertSame(1, $snapshot->inconsistentDone);
        self::assertSame(1, $snapshot->missing);
        self::assertSame(1, $this->jobs->requeueUnconfirmedDone($period));
        self::assertSame(0, $this->jobs->requeueUnconfirmedDone($period));
    }
}
