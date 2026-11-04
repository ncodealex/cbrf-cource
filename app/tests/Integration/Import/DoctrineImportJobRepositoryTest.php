<?php

declare(strict_types=1);

namespace App\Tests\Integration\Import;

use App\Cources\Infrastructure\Persistence\DoctrineRateSetRefreshStorage;
use App\Cources\Infrastructure\Persistence\DoctrineRateSetRepository;
use App\Import\Domain\Enum\RateImportAction;
use App\Import\Infrastructure\Persistence\DoctrineImportJobRepository;
use App\Shared\Contracts\Import\StaleImportExecution;
use App\Shared\Domain\DatePointParser;
use App\Tests\Unit\Cources\Domain\RateSets;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;

/**
 * @phpstan-import-type Params from DriverManager
 */
final class DoctrineImportJobRepositoryTest extends ImportJobStorageTestCase
{
    public function testRefreshResetAndRateSetDeletionRollbackTogether(): void
    {
        $date = DatePointParser::parse('2026-10-24');
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
            $this->jobs->write($date, $execution->version, static function () use (&$written): void { $written = true; });
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
        self::assertTrue($this->jobs->finish($claim, RateImportAction::Fail, 1, null, '12345678-1234-4234-8234-123456789abc', 'ошибка'));
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
        $other = DriverManager::getConnection($params);
        try {
            $other->executeStatement(\sprintf(
                <<<'SQL'
                    SET search_path TO %s
                    SQL,
                $this->schema,
            ));
            $otherJobs = new DoctrineImportJobRepository($other);
            $messages = $this->jobs->reserveDispatch(20, 600, 1);
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
            self::assertTrue($otherJobs->recover($this->queries->expired(20)[0], 1, DatePointParser::parse('2026-01-01')));
            self::assertFalse($this->jobs->recover($snapshot, 1, null));
            self::assertFalse($this->jobs->finish($claim, RateImportAction::Fail, 1));
        } finally {
            $other->close();
        }
    }
}
