<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Нарушение инварианта доменного значения.
 */
abstract class SharedDomainInvalidArgument extends \InvalidArgumentException
{
}
