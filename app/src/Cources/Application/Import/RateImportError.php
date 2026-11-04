<?php

declare(strict_types=1);

namespace App\Cources\Application\Import;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class RateImportError
{
    public function __construct(
        public string $requestedDate,
        public int $attempts,
        public string $errorCode,
        public string $errorText,
    ) {
    }
}
