<?php

declare(strict_types=1);

namespace App\Import\Application\DTO;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Ошибка одного задания в терминальном состоянии: дата, число попыток и безопасные для вывода сведения о сбое.
 */
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
