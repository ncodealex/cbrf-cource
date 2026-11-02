<?php

declare(strict_types=1);

namespace App\Cources\Application\Import;

use App\Cources\Application\Exception\InvalidRateImportTransition;

final readonly class RateImportTransitionPolicy
{
    /** @return list<RateImportStatus> */
    public function sources(RateImportAction $action): array
    {
        return match ($action) {
            RateImportAction::Claim => [RateImportStatus::Waiting, RateImportStatus::Deferred],
            RateImportAction::Complete, RateImportAction::Defer, RateImportAction::Fail, RateImportAction::Recover => [RateImportStatus::Processing],
            RateImportAction::Refresh => RateImportStatus::cases(),
            RateImportAction::RepairDone => [RateImportStatus::Done],
            RateImportAction::RetryError => [RateImportStatus::Error],
        };
    }

    public function assertAllowed(RateImportStatus $from, RateImportAction $action): void
    {
        if (!\in_array($from, $this->sources($action), true)) {
            throw new InvalidRateImportTransition($from, $action);
        }
    }
}
