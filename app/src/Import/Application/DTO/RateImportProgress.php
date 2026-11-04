<?php

declare(strict_types=1);

namespace App\Import\Application\DTO;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Снимок распределения дат периода по подтверждению и состояниям заданий; включает пропуски и некорректные Done.
 */
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
