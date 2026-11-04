<?php

declare(strict_types=1);

namespace App\Import\Domain\Exception;

use App\Import\Domain\Enum\RateImportAction;
use App\Import\Domain\Enum\RateImportStatus;

final class InvalidRateImportTransition extends \LogicException
{
    public function __construct(
        public readonly RateImportStatus $from,
        public readonly RateImportAction $action,
    ) {
        parent::__construct(
            \sprintf('Переход %s из состояния %s запрещён.', $action->name, $from->value),
        );
    }
}
