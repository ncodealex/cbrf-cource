<?php

declare(strict_types=1);

namespace App\Cources\Application\Exception;

use App\Shared\Contracts\Rates\RateImportPermanentFailure;

final class RateSetConflict extends \RuntimeException implements RateImportPermanentFailure
{
}
