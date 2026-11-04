<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Mapping;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class InvalidRateSetPayload extends \UnexpectedValueException
{
}
