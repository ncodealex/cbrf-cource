<?php

declare(strict_types=1);

namespace App\Cources\Application\Exception;

use App\Cources\Application\Import\RateImportAction;
use App\Cources\Application\Import\RateImportStatus;

final class InvalidRateImportTransition extends \LogicException
{
    public function __construct(public readonly RateImportStatus $from, public readonly RateImportAction $action)
    {
        parent::__construct(\sprintf('Переход %s из состояния %s запрещён.', $action->name, $from->value));
    }
}
