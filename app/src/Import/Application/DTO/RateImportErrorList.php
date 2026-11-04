<?php

declare(strict_types=1);

namespace App\Import\Application\DTO;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Страница терминальных ошибок вместе с общим числом ошибок до применения лимита.
 */
#[Exclude]
final readonly class RateImportErrorList
{
    /**
     * Список терминальных ошибок с общим количеством строк до применения лимита.
     *
     * @param list<RateImportError> $items показанные ошибки в порядке от новых к старым
     */
    public function __construct(
        public int $total,
        public array $items,
    ) {
    }
}
