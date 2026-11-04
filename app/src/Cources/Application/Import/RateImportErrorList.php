<?php

declare(strict_types=1);

namespace App\Cources\Application\Import;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

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
