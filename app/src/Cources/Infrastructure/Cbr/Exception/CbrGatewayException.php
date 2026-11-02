<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Cbr\Exception;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
abstract class CbrGatewayException extends RuntimeException
{
}
