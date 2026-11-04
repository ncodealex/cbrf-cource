<?php

declare(strict_types=1);

namespace App\Courses\Infrastructure\Mapping;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use UnexpectedValueException;

#[Exclude]
final class InvalidRateSetPayload extends UnexpectedValueException
{
}
