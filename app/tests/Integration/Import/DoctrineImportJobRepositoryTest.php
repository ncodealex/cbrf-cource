<?php

declare(strict_types=1);

namespace App\Tests\Integration\Import;

use App\Courses\Infrastructure\Persistence\DoctrineRateSetRefreshStorage;
use App\Courses\Infrastructure\Persistence\DoctrineRateSetRepository;
use App\Import\Domain\Entity\ImportJob;
use App\Import\Domain\Enum\RateImportAction;
use App\Import\Domain\Enum\RateImportStatus;
use App\Import\Domain\ValueObject\RatesImportPeriod;
use App\Import\Infrastructure\Persistence\DoctrineImportJobRepository;
use App\Shared\Contracts\Import\StaleImportExecution;
use App\Shared\Domain\DatePointParser;
use App\Tests\Unit\Cources\Domain\RateSets;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\DatePoint;
use function sprintf;

/**
 * @phpstan-import-type Params from DriverManager
 */
final class DoctrineImportJobRepositoryTest extends ImportJobStorageTestCase
{
    /**
     * @return iterable<string, array{string, RateImportStatus}>
     */
    public static function resetOperations(): iterable
    {
        yield 'retry error' => ['retry', RateImportStatus::Error];
        yield 'repair done' => ['repair', RateImportStatus::Done];
        yield 'refresh processing' => ['refresh', RateImportStatus::Processing];
        yield 'refresh deferred' => ['refresh', RateImportStatus::Deferred];
        yield 'refresh waiting' => ['refresh', RateImportStatus::Waiting];
    }

    #[DataProvider('resetOperations')]
    public function testBulkResetMatchesDomain(string $operation, RateImportStatus $status): void
    {
        $date = DatePointParser::parse('2026-01-01');
        $old  = new DatePoint('2025-12-31 12:00:00 Europe/Moscow');
        $job  = new ImportJob($date, $status, 7, 3, $old, $old, $old, $old, '12345678-1234-4234-8234-123456789abc',
            'Ошибка');
        $this->jobs->register([$date]);
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE rate_import_jobs
                SET status = :status, version = 7, attempts = 3,
                    available_at = :old, lease_until = :old, dispatched_at = :old,
                    completed_at = :old, error_code = '12345678-1234-4234-8234-123456789abc', error_text = 'Ошибка'
                WHERE requested_date = :date
                SQL,
            ['status' => $status->value, 'old' => $old->format('Y-m-d H:i:s.uP'), 'date' => '2026-01-01'],
        );

        $before = new DatePoint();
        match ($operation) {
            'retry' => $this->jobs->retryErrors('2026-01-01'),
            'repair' => $this->jobs->requeueUnconfirmedDone(new RatesImportPeriod($date, $date)),
            'refresh' => $this->jobs->resetForRefresh($date),
            default => throw new LogicException('Неизвестная операция.'),
        };
        $after = new DatePoint();
        match ($operation) {
            'retry' => $job->retryError($before),
            'repair' => $job->repairDone($before, false),
            'refresh' => $job->resetForRefresh($before),
        };

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT status, version, attempts, available_at, lease_until,
                    dispatched_at, completed_at, error_code, error_text
                FROM rate_import_jobs
                WHERE requested_date = :date
                SQL,
            ['date' => '2026-01-01'],
        );
        self::assertIsArray($row);
        self::assertSame($job->status->value, $row['status']);
        self::assertSame($job->version, $row['version']);
        self::assertSame($job->attempts, $row['attempts']);
        foreach (['lease_until', 'dispatched_at', 'completed_at', 'error_code', 'error_text'] as $field) {
            self::assertNull($row[$field]);
        }
        self::assertNotNull($job->availableAt);
        self::assertIsString($row['available_at']);
        $stored = new DatePoint($row['available_at']);
        self::assertGreaterThanOrEqual((float)$before->format('U.u') - 0.01, (float)$stored->format('U.u'));
        self::assertLessThanOrEqual((float)$after->format('U.u') + 0.01, (float)$stored->format('U.u'));
    }

    public function testRefreshResetAndRateSetDeletionRollbackTogether(): void
    {
        $date  = DatePointParser::parse('2026-10-24');
        $rates = new DoctrineRateSetRepository($this->connection, $this->mapper);
        $rates->save($date, RateSets::saturday());
        $this->jobs->register([$date]);
        $execution = $this->jobs->claim('2026-10-24', 0, 120);
        self::assertNotNull($execution);
        $storage = new DoctrineRateSetRefreshStorage($this->connection, $this->jobs, $rates);
        $this->connection->executeStatement(
            <<<'SQL'
                CREATE FUNCTION reject_rate_delete() RETURNS trigger LANGUAGE plpgsql AS
                'BEGIN RAISE EXCEPTION ''Тестовый отказ удаления''; END'
                SQL,
        );
        $this->connection->executeStatement(
            <<<'SQL'
                CREATE TRIGGER reject_rate_delete BEFORE DELETE ON rate_sets
                FOR EACH ROW EXECUTE FUNCTION reject_rate_delete()
                SQL,
        );
        try {
            $storage->invalidate($date, $date);
            self::fail('Ожидался отказ удаления.');
        } catch (Exception) {
            $storedVersion = $this->connection->fetchOne(
                <<<'SQL'
                    SELECT version
                    FROM rate_import_jobs
                    SQL,
            );
            self::assertSame(1, $storedVersion);
            self::assertTrue($this->queries->isConfirmed('2026-10-24'));
        }
        $this->connection->executeStatement(
            <<<'SQL'
                DROP TRIGGER reject_rate_delete ON rate_sets
                SQL,
        );
        $storage->invalidate($date, $date);
        self::assertFalse($this->queries->isConfirmed('2026-10-24'));
        $written = false;
        try {
            $this->jobs->write($date, $execution->version, static function () use (&$written): void {
                $written = true;
            });
            self::fail('Устаревший Worker не должен писать данные.');
        } catch (StaleImportExecution) {
            self::assertFalse($written);
        }
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
        self::assertTrue($this->jobs->finish($claim, RateImportAction::Fail, 1, null,
            '12345678-1234-4234-8234-123456789abc', 'ошибка'));
        self::assertFalse($this->jobs->finish($claim, RateImportAction::Defer, 1));
        self::assertSame(1, $this->queries->errors(null, 100)->total);
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
        /**
         * @var Params $params
         */
        $params = $this->connection->getParams();
        $other  = DriverManager::getConnection($params);
        try {
            $other->executeStatement(sprintf(
                <<<'SQL'
                    SET search_path TO %s
                    SQL,
                $this->schema,
            ));
            $otherJobs = new DoctrineImportJobRepository($other);
            $messages  = $this->jobs->reserveDispatch(20, 600, 1);
            self::assertCount(1, $messages);
            self::assertSame([], $otherJobs->reserveDispatch(20, 600, 1));
            $claim = $otherJobs->claim($messages[0]->requestedDate, $messages[0]->version, 120);
            self::assertNotNull($claim);
            self::assertNull($this->jobs->claim($messages[0]->requestedDate, 0, 120));
            self::assertCount(1, $this->jobs->reserveDispatch(20, 600, 1));
            $this->connection->executeStatement(
                <<<'SQL'
                    UPDATE rate_import_jobs
                    SET lease_until = NOW() - INTERVAL '1 second'
                    WHERE status = 'processing'
                    SQL,
            );
            self::assertCount(1, $this->queries->expired(20));
            $snapshot = $this->queries->expired(20)[0];
            self::assertTrue($otherJobs->recover($this->queries->expired(20)[0], 1,
                DatePointParser::parse('2026-01-01')));
            self::assertFalse($this->jobs->recover($snapshot, 1, null));
            self::assertFalse($this->jobs->finish($claim, RateImportAction::Fail, 1));
        } finally {
            $other->close();
        }
    }
}
