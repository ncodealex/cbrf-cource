<?php

declare(strict_types=1);

namespace App\Courses\Domain\Exception\ExchangeRate;

use App\Shared\Domain\Exception\SharedDomainInvalidArgument;

final class InvalidRateDateOrder extends SharedDomainInvalidArgument
{
}
