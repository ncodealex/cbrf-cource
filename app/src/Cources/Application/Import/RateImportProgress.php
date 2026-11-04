<?php

declare(strict_types=1);

namespace App\Cources\Application\Import;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class RateImportProgress
{
    public function __construct(
        public int $confirmed,
        public int $waiting,
        public int $processing,
        public int $deferred,
        public int $error,
        public int $inconsistentDone,
        public int $missing,
    ) {
    }
}
