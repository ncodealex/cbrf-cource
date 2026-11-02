<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cources\Import;

use App\Cources\Application\Exception\InvalidRateImportTransition;
use App\Cources\Application\Import\RateImportAction;
use App\Cources\Application\Import\RateImportStatus;
use App\Cources\Application\Import\RateImportTransitionPolicy;
use PHPUnit\Framework\TestCase;

final class RateImportLifecycleTest extends TestCase
{
    public function testAllTransitions(): void
    {
        $policy = new RateImportTransitionPolicy();
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
                    $policy->assertAllowed($status, $action);
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
