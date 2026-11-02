<?php

declare(strict_types=1);

namespace App\Cources\Application\Import;

enum RateImportAction
{
    case Claim;
    case Complete;
    case Defer;
    case Fail;
    case Recover;
    case Refresh;
    case RepairDone;
    case RetryError;
}
