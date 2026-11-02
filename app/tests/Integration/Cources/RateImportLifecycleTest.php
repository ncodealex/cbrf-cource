<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cources;

use App\Cources\Application\Import\RateImportAction;
use App\Cources\Application\Import\RatesImportPeriod;
use App\Cources\Infrastructure\Persistence\DoctrineRateImportJobRepository;
use App\Shared\Domain\DatePointParser;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;

/** @phpstan-import-type Params from DriverManager */
final class RateImportLifecycleTest extends RateSetStorageTestCase
{
    private DoctrineRateImportJobRepository $jobs;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Version20261102120000', 'Version20261102150000'] as $name) {
            require_once \dirname(__DIR__, 3).'/migrations/'.$name.'.php';
            $class = 'DoctrineMigrations\\'.$name;
            $migration = new $class($this->connection, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $this->connection->executeStatement($query->getStatement());
            }
        }
        $this->jobs = new DoctrineRateImportJobRepository($this->connection);
    }

    public function testClaimFencingRetryAndRefresh(): void
    {
        $date = DatePointParser::parse('2026-01-01');
        self::assertCount(1, $this->jobs->register([$date]));
        self::assertSame([], $this->jobs->register([$date]));
        $claim = $this->jobs->claim('2026-01-01', 0, 120);
        self::assertNotNull($claim);
        self::assertSame(1, $claim->version);
        self::assertNull($this->jobs->claim('2026-01-01', 0, 120));
        self::assertTrue($this->jobs->finish($claim, RateImportAction::Fail, 1, null, '12345678-1234-4234-8234-123456789abc', 'ошибка'));
        self::assertFalse($this->jobs->finish($claim, RateImportAction::Defer, 1));
        self::assertSame(1, $this->jobs->errors(null, 100)['total']);
        self::assertSame(1, $this->jobs->retryErrors('2026-01-01'));
        self::assertSame(0, $this->jobs->retryErrors(null));
        $fresh = $this->jobs->claim('2026-01-01', 3, 120);
        self::assertNotNull($fresh);
        $this->jobs->resetForRefresh($date);
        self::assertFalse($this->jobs->finish($fresh, RateImportAction::Complete, 1));
    }

    public function testDispatchReservationsAndIndependentClaim(): void
    {
        $this->jobs->register([DatePointParser::parse('2026-01-01'), DatePointParser::parse('2026-01-02')]);
        /** @var Params $params */
        $params = $this->connection->getParams();
        $other = DriverManager::getConnection($params);
        try {
            $other->executeStatement('SET search_path TO '.$this->schema);
            $otherJobs = new DoctrineRateImportJobRepository($other);
            $messages = $this->jobs->reserveDispatch(20, 600, 1);
            self::assertCount(1, $messages);
            self::assertSame([], $otherJobs->reserveDispatch(20, 600, 1));
            $claim = $otherJobs->claim($messages[0]->requestedDate, $messages[0]->version, 120);
            self::assertNotNull($claim);
            self::assertNull($this->jobs->claim($messages[0]->requestedDate, 0, 120));
            self::assertCount(1, $this->jobs->reserveDispatch(20, 600, 1));
            $this->connection->executeStatement("UPDATE rate_import_jobs SET lease_until = NOW() - INTERVAL '1 second' WHERE status = 'processing'");
            self::assertCount(1, $this->jobs->expired(20));
            self::assertTrue($otherJobs->recover($claim, 1, DatePointParser::parse('2026-01-01')));
            self::assertFalse($this->jobs->recover($claim, 1, null));
            self::assertFalse($this->jobs->finish($claim, RateImportAction::Fail, 1));
        } finally {
            $other->close();
        }
    }

    public function testProgressDoesNotCountUnconfirmedDone(): void
    {
        $date = DatePointParser::parse('2026-01-01');
        $this->jobs->register([$date]);
        $this->connection->executeStatement("UPDATE rate_import_jobs SET status = 'done'");
        $period = new RatesImportPeriod($date, DatePointParser::parse('2026-01-02'));
        $snapshot = $this->jobs->progress($period);
        self::assertSame(0, $snapshot['confirmed']);
        self::assertSame(1, $snapshot['inconsistentDone']);
        self::assertSame(1, $snapshot['missing']);
        self::assertSame(1, $this->jobs->requeueUnconfirmedDone($period));
        self::assertSame(0, $this->jobs->requeueUnconfirmedDone($period));
    }

    public function testConcurrentClaimHasExactlyOneOwner(): void
    {
        $this->jobs->register([DatePointParser::parse('2026-01-01')]);
        $this->connection->beginTransaction();
        self::assertNotNull($this->jobs->claim('2026-01-01', 0, 120));
        $this->compete('claim');
        self::assertSame(1, $this->connection->fetchOne('SELECT version FROM rate_import_jobs'));
    }

    public function testConcurrentRetryUpdatesOnlyOneVersion(): void
    {
        $this->jobs->register([DatePointParser::parse('2026-01-01')]);
        $this->connection->executeStatement("UPDATE rate_import_jobs SET status = 'error'");
        $this->connection->beginTransaction();
        self::assertSame(1, $this->jobs->retryErrors('2026-01-01'));
        $this->compete('retry');
        self::assertSame(1, $this->connection->fetchOne('SELECT version FROM rate_import_jobs'));
    }

    public function testConcurrentDispatcherHonoursGlobalCapacity(): void
    {
        $this->jobs->register([DatePointParser::parse('2026-01-01'), DatePointParser::parse('2026-01-02')]);
        $this->connection->beginTransaction();
        self::assertCount(1, $this->jobs->reserveDispatch(20, 600, 1));
        $this->compete('dispatch');
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM rate_import_jobs WHERE dispatched_at IS NOT NULL'));
    }

    private function compete(string $action): void
    {
        $process = new \Symfony\Component\Process\Process([\PHP_BINARY, \dirname(__DIR__, 2).'/Fixtures/import-race-worker.php']);
        $process->setInput(json_encode(['params' => $this->connection->getParams(), 'schema' => $this->schema, 'action' => $action], \JSON_THROW_ON_ERROR));
        $process->setTimeout(15);
        $process->start();
        try {
            $deadline = microtime(true) + 8;
            $blocked = false;
            do {
                $this->connection->executeStatement('SELECT pg_stat_clear_snapshot()');
                $blocked = 0 < $this->connection->fetchOne("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name = :name AND wait_event_type = 'Lock'", ['name' => $this->schema]);
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
        $this->connection->executeStatement("INSERT INTO rate_import_jobs (requested_date, status, available_at) SELECT day::date, 'waiting', NOW() + INTERVAL '1 year' FROM generate_series('1993-01-01'::date, '2026-01-01'::date, '1 day') dates(day)");
        $this->connection->executeStatement("UPDATE rate_import_jobs SET available_at = NOW() WHERE requested_date = '2026-01-01'");
        $this->connection->executeStatement('ANALYZE rate_import_jobs');
        $ready = $this->connection->fetchFirstColumn("EXPLAIN (ANALYZE, BUFFERS) SELECT requested_date FROM rate_import_jobs WHERE status IN ('waiting', 'deferred') AND available_at <= NOW() ORDER BY available_at, requested_date LIMIT 20 FOR UPDATE SKIP LOCKED");
        self::assertStringContainsString('idx_rate_import_jobs_ready', implode("\n", array_map(static function (mixed $line): string {
            self::assertIsString($line);

            return $line;
        }, $ready)));
        $expired = $this->connection->fetchFirstColumn("EXPLAIN (ANALYZE, BUFFERS) SELECT requested_date FROM rate_import_jobs WHERE status = 'processing' AND lease_until <= NOW() ORDER BY lease_until, requested_date LIMIT 20 FOR UPDATE SKIP LOCKED");
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
        self::assertSame([], $this->jobs->expired(20));
        $this->connection->executeStatement("UPDATE rate_import_jobs SET lease_until=NOW()-INTERVAL '1 second'");
        /** @var Params $params */
        $params = $this->connection->getParams();
        $other = DriverManager::getConnection($params);
        try {
            $other->executeStatement('SET search_path TO '.$this->schema);
            $this->connection->beginTransaction();
            self::assertTrue($this->jobs->recover($claim, 5, null));
            self::assertFalse(new DoctrineRateImportJobRepository($other)->recover($claim, 5, null));
            $this->connection->commit();
            self::assertSame(1, $this->jobs->errors(null, 100)['total']);
            self::assertFalse($this->jobs->finish($claim, RateImportAction::Complete, 0));
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $other->close();
        }
    }
}
