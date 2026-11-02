<?php

declare(strict_types=1);

namespace App\Cources\Application\Message;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class ProcessRateImportMessage
{
    public function __construct(public string $requestedDate, public int $version)
    {
    }
}
