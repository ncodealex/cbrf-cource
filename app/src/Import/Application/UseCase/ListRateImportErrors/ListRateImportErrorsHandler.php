<?php

declare(strict_types=1);

namespace App\Import\Application\UseCase\ListRateImportErrors;

use App\Import\Application\Contract\ImportJobQueryRepositoryInterface;
use App\Import\Application\DTO\RateImportErrorList;

final readonly class ListRateImportErrorsHandler
{
    public function __construct(
        private ImportJobQueryRepositoryInterface $repository,
    ) {
    }

    public function __invoke(
        ?string $date = null,
        int $limit = 100,
    ): RateImportErrorList {
        // 1. Проверить допустимый размер выдачи.
        if ($limit < 1 || $limit > 10000) {
            throw new \InvalidArgumentException('Лимит должен быть от 1 до 10000.');
        }

        // 2. Получить ошибки и общее количество для фильтра по дате.
        return $this->repository->errors($date, $limit);
    }
}
