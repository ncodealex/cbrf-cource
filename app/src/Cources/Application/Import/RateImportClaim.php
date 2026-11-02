<?php

declare(strict_types=1);

namespace App\Cources\Application\Import;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class RateImportClaim
{
    public function __construct(public string $requestedDate, public int $version, public int $attempts)
    {
    }
}
