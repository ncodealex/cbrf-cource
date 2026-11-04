<?php

declare(strict_types=1);

namespace App\Courses\Application\Exception;

use App\Shared\Domain\Exception\SharedDomainInvalidArgument;

final class RateSetNotFound extends SharedDomainInvalidArgument
{
}
