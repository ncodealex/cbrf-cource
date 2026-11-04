<?php

declare(strict_types=1);

namespace App\Tests\Integration\Import;

use App\Import\Domain\Enum\RateImportAction;
use App\Import\Infrastructure\Persistence\DoctrineImportJobRepository;
use App\Shared\Domain\DatePointParser;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Process\Process;

/**
 * @phpstan-import-type Params from DriverManager
 */
final class RateImportConcurrencyTest extends ImportJobStorageTestCase
{
    public function testConcurrentClaimHasExactlyOneOwner(): void
    {
        $this->jobs->register([DatePointParser::parse('2026-01-01')]);
        $this->connection->beginTransaction();
        self::assertNotNull($this->jobs->claim('2026-01-01', 0, 120));
        $this->compete('claim');
        $storedVersion = $this->connection->fetchOne(
            <<<'SQL'
                SELECT
                    version
                FROM rate_import_jobs
                SQL,
        );
        self::assertSame(1, $storedVersion);
    }

    public function testConcurrentRetryUpdatesOnlyOneVersion(): void
    {
        $this->jobs->register([DatePointParser::parse('2026-01-01')]);
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE rate_import_jobs
                SET status = 'error'
                SQL,
        );
        $this->connection->beginTransaction();
        self::assertSame(1, $this->jobs->retryErrors('2026-01-01'));
        $this->compete('retry');
        $storedVersion = $this->connection->fetchOne(
            <<<'SQL'
                SELECT
                    version
                FROM rate_import_jobs
                SQL,
        );
        self::assertSame(1, $storedVersion);
    }

    public function testConcurrentDispatcherHonoursGlobalCapacity(): void
    {
        $this->jobs->register([DatePointParser::parse('2026-01-01'), DatePointParser::parse('2026-01-02')]);
        $this->connection->beginTransaction();
        self::assertCount(1, $this->jobs->reserveDispatch(20, 600, 1));
        $this->compete('dispatch');
        $dispatchedCount = $this->connection->fetchOne(
            <<<'SQL'
                SELECT
                    COUNT(*)
                FROM rate_import_jobs
                WHERE dispatched_at IS NOT NULL
                SQL,
        );
        self::assertSame(1, $dispatchedCount);
    }

    private function compete(string $action): void
    {
        $process = new Process([\PHP_BINARY, \dirname(__DIR__, 2).'/Fixtures/Import/import-race-worker.php']);
        $process->setInput(json_encode(['params' => $this->connection->getParams(), 'schema' => $this->schema, 'action' => $action], \JSON_THROW_ON_ERROR));
        $process->setTimeout(15);
        $process->start();
        try {
            $deadline = microtime(true) + 8;
            $blocked = false;
            do {
                $this->connection->executeStatement(
                    <<<'SQL'
                        SELECT
                            pg_stat_clear_snapshot()
                        SQL,
                );
                $waitingConnectionCount = $this->connection->fetchOne(
                    <<<'SQL'
                        SELECT
                            COUNT(*)
                        FROM pg_stat_activity
                        WHERE application_name = :name
                            AND wait_event_type = 'Lock'
                        SQL,
                    ['name' => $this->schema],
                );
                $blocked = 0 < $waitingConnectionCount;
                if ($blocked || !$process->isRunning()) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertTrue($blocked, $process->getErrorOutput());
            $this->connection->commit();
            $process->wait();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            self::assertSame('0', $process->getOutput());
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $process->stop();
        }
    }

    public function testExplainUsesExistingIndexes(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO rate_import_jobs (
                    requested_date, status, available_at
                )
                SELECT
                    day::date,
                    'waiting',
                    NOW() + INTERVAL '1 year'
                FROM generate_series(
                        '1993-01-01'::date, '2026-01-01'::date,
                        '1 day'
                    ) dates(day)
                SQL,
        );
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE rate_import_jobs
                SET available_at = NOW()
                WHERE requested_date = '2026-01-01'
                SQL,
        );
        $this->connection->executeStatement(
            <<<'SQL'
                ANALYZE rate_import_jobs
                SQL,
        );
        $ready = $this->connection->fetchFirstColumn(
            <<<'SQL'
                EXPLAIN (ANALYZE, BUFFERS)
                SELECT
                    requested_date
                FROM rate_import_jobs
                WHERE status IN ('waiting', 'deferred')
                    AND available_at <= NOW()
                ORDER BY available_at,
                    requested_date
                LIMIT 20
                FOR UPDATE SKIP LOCKED
                SQL,
        );
        self::assertStringContainsString('idx_rate_import_jobs_ready', implode("\n", array_map(static function (mixed $line): string {
            self::assertIsString($line);

            return $line;
        }, $ready)));
        $expired = $this->connection->fetchFirstColumn(
            <<<'SQL'
                EXPLAIN (ANALYZE, BUFFERS)
                SELECT
                    requested_date
                FROM rate_import_jobs
                WHERE status = 'processing'
                    AND lease_until <= NOW()
                ORDER BY lease_until,
                    requested_date
                LIMIT 20
                FOR UPDATE SKIP LOCKED
                SQL,
        );
        self::assertStringContainsString('idx_rate_import_jobs_expired_lease', implode("\n", array_map(static function (mixed $line): string {
            self::assertIsString($line);

            return $line;
        }, $expired)));
    }

    public function testRecoverySkipsConcurrentOwnerAndExpiredBudgetBecomesError(): void
    {
        $date = DatePointParser::parse('2026-01-01');
        $this->jobs->register([$date]);
        $claim = $this->jobs->claim('2026-01-01', 0, 120);
        self::assertNotNull($claim);
        self::assertSame([], $this->queries->expired(20));
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE rate_import_jobs
                SET lease_until = NOW() - INTERVAL '1 second'
                SQL,
        );
        /**
         * @var Params $params
         */
        $params = $this->connection->getParams();
        $other = DriverManager::getConnection($params);
        try {
            $other->executeStatement(\sprintf(
                <<<'SQL'
                    SET search_path TO %s
                    SQL,
                $this->schema,
            ));
            $snapshot = $this->queries->expired(20)[0];
            $this->connection->beginTransaction();
            self::assertTrue($this->jobs->recover($snapshot, 5, null));
            self::assertFalse(new DoctrineImportJobRepository($other)->recover($snapshot, 5, null));
            $this->connection->commit();
            self::assertSame(1, $this->queries->errors(null, 100)->total);
            self::assertFalse($this->jobs->finish($claim, RateImportAction::Complete, 0));
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $other->close();
        }
    }
}
