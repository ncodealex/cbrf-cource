<?php

declare(strict_types=1);

namespace App\Import\Domain\ValueObject;

use Symfony\Component\Clock\DatePoint;

final readonly class ImportJobExecution
{
    public function __construct(
        public DatePoint $requestedDate,
        public int $version,
        public int $attempts,
    ) {
    }
}
