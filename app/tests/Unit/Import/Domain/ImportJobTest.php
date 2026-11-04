<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import\Domain;

use App\Import\Domain\Entity\ImportJob;
use App\Import\Domain\Enum\RateImportStatus;
use App\Import\Domain\Exception\InvalidRateImportTransition;
use App\Shared\Domain\DatePointParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ImportJobTest extends TestCase
{
    public function testLifecycleAndExecutionToken(): void
    {
        $now = new MockClock('2026-01-02')->now();
        $job = new ImportJob(DatePointParser::parse('2026-01-01'));
        self::assertSame(RateImportStatus::Waiting, $job->status);
        $execution = $job->claim($now, $now->modify('+120 seconds'));
        self::assertSame($job->requestedDate, $execution->requestedDate);
        self::assertSame(1, $execution->version);
        self::assertSame(0, $execution->attempts);
        self::assertTrue(new \ReflectionClass($execution)->isReadOnly());
        $job->defer($now->modify('+1 second'), 1, 'code', 'error');
        self::assertSame(2, $job->version);
        self::assertNull($job->leaseUntil);
        $job->claim($now->modify('+2 seconds'), $now->modify('+120 seconds'));
        $job->fail($now, 2, 'code', 'error');
        self::assertSame(RateImportStatus::Error, $job->status);
        $job->retryError($now);
        self::assertSame(0, $job->attempts);
        self::assertNull($job->errorCode);
        self::assertNull($job->errorText);
        $job->claim($now, $now->modify('+120 seconds'));
        $job->complete($now, true, 1);
        self::assertSame(RateImportStatus::Done, $job->status);
        self::assertSame($now, $job->completedAt);
        $job->repairDone($now, false);
        self::assertNull($job->completedAt);
        $version = $job->version;
        $job->resetForRefresh($now);
        self::assertSame($version + 1, $job->version);
    }

    public function testRecoveryRequiresExpiredLease(): void
    {
        $now = new MockClock()->now();
        $job = new ImportJob(DatePointParser::parse('2026-01-01'));
        $job->claim($now, $now->modify('+1 second'));
        try {
            $job->recover($now, 1, null, 'incident');
            self::fail('Восстановление действующего lease запрещено.');
        } catch (\LogicException) {
            self::assertSame(1, $job->version);
        }
        $job->recover($now->modify('+2 seconds'), 1, $now->modify('+3 seconds'), 'incident');
        self::assertSame(RateImportStatus::Deferred, $job->status);
        self::assertNull($job->leaseUntil);
        $job->claim($now->modify('+4 seconds'), $now->modify('+5 seconds'));
        $job->recover($now->modify('+6 seconds'), 2, null, 'incident');
        self::assertSame(RateImportStatus::Error, $job->status);
    }

    public function testUnconfirmedCompletionDoesNotMutateJob(): void
    {
        $now = new MockClock()->now();
        $job = new ImportJob(DatePointParser::parse('2026-01-01'));
        $job->claim($now, $now->modify('+120 seconds'));
        $this->expectException(\LogicException::class);
        try {
            $job->complete($now, false, 1);
        } finally {
            self::assertSame(RateImportStatus::Processing, $job->status);
            self::assertSame(1, $job->version);
        }
    }

    public function testDoneCannotBeClaimed(): void
    {
        $now = new MockClock()->now();
        $job = new ImportJob(DatePointParser::parse('2026-01-01'));
        $job->claim($now, $now->modify('+120 seconds'));
        $job->complete($now, true, 1);
        $this->expectException(InvalidRateImportTransition::class);
        $job->claim($now, $now->modify('+120 seconds'));
    }
}
