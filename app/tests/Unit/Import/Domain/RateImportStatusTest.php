<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import\Domain;

use App\Import\Domain\Enum\RateImportAction;
use App\Import\Domain\Enum\RateImportStatus;
use App\Import\Domain\Exception\InvalidRateImportTransition;
use PHPUnit\Framework\TestCase;

final class RateImportStatusTest extends TestCase
{
    public function testAllTransitions(): void
    {
        foreach (RateImportStatus::cases() as $status) {
            foreach (RateImportAction::cases() as $action) {
                $allowed = match ($action) {
                    RateImportAction::Claim => \in_array($status, [RateImportStatus::Waiting, RateImportStatus::Deferred], true),
                    RateImportAction::Complete, RateImportAction::Defer, RateImportAction::Fail, RateImportAction::Recover => RateImportStatus::Processing === $status,
                    RateImportAction::Refresh => true,
                    RateImportAction::RepairDone => RateImportStatus::Done === $status,
                    RateImportAction::RetryError => RateImportStatus::Error === $status,
                };
                try {
                    self::assertSame($allowed, $status->allows($action));
                    self::assertSame($allowed, \in_array($status, RateImportStatus::allowedFor($action), true));
                    $status->assertAllows($action);
                    self::assertTrue($allowed);
                } catch (InvalidRateImportTransition $e) {
                    self::assertFalse($allowed);
                    self::assertSame($status, $e->from);
                    self::assertSame($action, $e->action);
                }
            }
        }
    }
}
