<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cources;

use App\Cources\Application\Exception\RateSetConflict;
use App\Cources\Application\Exception\RateSetNotFound;
use App\Shared\Domain\DatePointParser;
use App\Cources\Infrastructure\Persistence\DoctrineRateSetRepository;
use App\Tests\Unit\Cources\Domain\RateSets;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

final class DoctrineRateSetRepositoryTest extends RateSetStorageTestCase
{
    public function testFirstSaveAndIdempotency(): void
    {
        $repository = new DoctrineRateSetRepository($this->connection, $this->mapper);
        $date = DatePointParser::parse('2026-10-26');
        $set = RateSets::saturday();
        $repository->save($date, $set);
        $createdAt = $this->connection->fetchOne('SELECT created_at FROM rate_sets');
        $repository->save($date, $set);
        $repository->save(DatePointParser::parse('2026-10-25'), $set);
        self::assertSame($set->contentHash(), $repository->get($date)->contentHash());
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM rate_sets'));
        self::assertSame(2, $this->connection->fetchOne('SELECT COUNT(*) FROM rate_request_dates'));
        self::assertSame($createdAt, $this->connection->fetchOne('SELECT created_at FROM rate_sets'));
        self::assertSame('81.4500', (string) $repository->get($date)->items['USD']->value);
    }

    public function testDifferentContentConflicts(): void
    {
        $repository = new DoctrineRateSetRepository($this->connection, $this->mapper);
        $repository->save(DatePointParser::parse('2026-10-26'), RateSets::saturday());
        $this->expectException(RateSetConflict::class);
        $repository->save(DatePointParser::parse('2026-10-27'), RateSets::create('2026-10-24', [['USD', 'R01235', 1, '1.0000']]));
    }

    public function testMappingConflictRollsBackNewSet(): void
    {
        $repository = new DoctrineRateSetRepository($this->connection, $this->mapper);
        $date = DatePointParser::parse('2026-10-26');
        $repository->save($date, RateSets::friday());
        try {
            $repository->save($date, RateSets::saturday());
            self::fail('Ожидался конфликт календарной даты');
        } catch (RateSetConflict) {
            self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM rate_sets'));
            self::assertSame('2026-10-23', $repository->get($date)->date());
            self::assertFalse($this->connection->isTransactionActive());
        }
    }

    public function testMissingMappingDoesNotUseNearestDate(): void
    {
        $repository = new DoctrineRateSetRepository($this->connection, $this->mapper);
        $repository->save(DatePointParser::parse('2026-10-24'), RateSets::saturday());
        $this->expectException(RateSetNotFound::class);
        $repository->get(DatePointParser::parse('2026-10-25'));
    }

    public function testForeignKeyRejectsMissingSet(): void
    {
        $this->expectException(\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException::class);
        $this->connection->executeStatement("INSERT INTO rate_request_dates VALUES ('2026-10-26', '2026-10-24')");
    }

    public function testLatestCanBeSavedForItsEffectiveDate(): void
    {
        $set = RateSets::saturday();
        $repository = new DoctrineRateSetRepository($this->connection, $this->mapper);
        $repository->save($set->effectiveDate, $set);
        self::assertSame($set->contentHash(), $repository->get($set->effectiveDate)->contentHash());
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function concurrentSaves(): iterable
    {
        yield 'одна дата одинаковое содержимое' => ['2026-10-26', 'same', false];
        yield 'разные даты одинаковое содержимое' => ['2026-10-27', 'same', false];
        yield 'одна дата действия разное содержимое' => ['2026-10-27', 'content', true];
        yield 'одна дата запроса разные даты действия' => ['2026-10-26', 'date', true];
    }

    #[DataProvider('concurrentSaves')]
    public function testConcurrentSave(string $requested, string $variant, bool $conflict): void
    {
        $repository = new DoctrineRateSetRepository($this->connection, $this->mapper);
        $this->connection->beginTransaction();
        $repository->save(DatePointParser::parse('2026-10-26'), RateSets::saturday());
        $process = new Process([\PHP_BINARY, \dirname(__DIR__, 2).'/Fixtures/rateset-save-worker.php']);
        $process->setInput(json_encode([
            'params' => $this->connection->getParams(), 'schema' => $this->schema,
            'requested' => $requested, 'variant' => $variant,
        ], \JSON_THROW_ON_ERROR));
        $process->setTimeout(15);
        $process->start();
        try {
            // Ждём реального ожидания блокировки уникальности, а не произвольную задержку.
            $deadline = microtime(true) + 8;
            $blocked = false;
            do {
                $this->connection->executeStatement('SELECT pg_stat_clear_snapshot()');
                $count = $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM pg_stat_activity WHERE application_name = :name AND wait_event_type = 'Lock'",
                    ['name' => $this->schema],
                );
                self::assertIsInt($count);
                $blocked = $count > 0;
                if ($blocked || !$process->isRunning()) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertTrue($blocked, 'Второй процесс должен ожидать завершения конкурентной вставки: '.$process->getErrorOutput());
            $this->connection->commit();
            $process->wait();
            self::assertSame($conflict ? 2 : 0, $process->getExitCode(), $process->getErrorOutput());
            self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM rate_sets'));
            self::assertSame(!$conflict && '2026-10-27' === $requested ? 2 : 1, $this->connection->fetchOne('SELECT COUNT(*) FROM rate_request_dates'));
            self::assertSame(RateSets::saturday()->contentHash(), $repository->get(DatePointParser::parse('2026-10-26'))->contentHash());
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $process->stop();
        }
    }
}
