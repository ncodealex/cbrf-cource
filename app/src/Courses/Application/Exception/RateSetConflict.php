<?php

declare(strict_types=1);

namespace App\Courses\Application\Exception;

use App\Shared\Contracts\Rates\RateImportPermanentFailure;
use RuntimeException;

final class RateSetConflict extends RuntimeException implements RateImportPermanentFailure
{
}
