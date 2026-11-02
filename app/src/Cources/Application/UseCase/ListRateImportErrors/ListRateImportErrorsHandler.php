<?php

declare(strict_types=1);

namespace App\Cources\Application\UseCase\ListRateImportErrors;

use App\Cources\Application\Contract\RateImportJobRepositoryInterface;

final readonly class ListRateImportErrorsHandler
{
    public function __construct(private RateImportJobRepositoryInterface $repository)
    {
    }

    /** @return array{total: int, rows: list<array{requested_date: string, attempts: int, error_code: string, error_text: string}>} */
    public function __invoke(?string $date = null, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 10000) {
            throw new \InvalidArgumentException('Лимит должен быть от 1 до 10000.');
        }

        return $this->repository->errors($date, $limit);
    }
}
