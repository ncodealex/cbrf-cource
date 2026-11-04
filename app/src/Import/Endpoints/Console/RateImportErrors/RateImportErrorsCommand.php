<?php

declare(strict_types=1);

namespace App\Import\Endpoints\Console\RateImportErrors;

use App\Import\Application\UseCase\ListRateImportErrors\ListRateImportErrorsHandler;
use App\Shared\Domain\DateFormatInterface;
use App\Shared\Domain\DatePointParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Показывает терминальные ошибки импорта с фильтром по дате и лимитом строк.
 */
#[AsCommand(name: 'app:rates:errors', description: 'Просмотр терминальных ошибок импорта')]
final readonly class RateImportErrorsCommand
{
    public function __construct(
        private ListRateImportErrorsHandler $handler,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option]
        ?string $date = null,
        #[Option]
        string $limit = '100',
    ): int {
        try {
            $result = ($this->handler)($this->validateDate($date), $this->validateLimit($limit));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
        $io->title('Ошибочные задания импорта');
        $rows = [];
        foreach ($result->items as $error) {
            $rows[] = [
                $error->requestedDate,
                $error->attempts,
                $error->errorCode,
                OutputFormatter::escape(preg_replace('/[\x00-\x1f\x7f-\x9f]/u', ' ', $error->errorText) ?? ''),
            ];
        }
        $io->table(['Дата', 'Попытки', 'Код ошибки', 'Ошибка'], $rows);
        $io->text(\sprintf('Всего ошибочных заданий: %d. Показано: %d.', $result->total, \count($rows)));

        return Command::SUCCESS;
    }

    private function validateDate(?string $date): ?string
    {
        return null === $date ? null : DatePointParser::parse($date)->format(DateFormatInterface::ISO);
    }

    private function validateLimit(string $limit): int
    {
        $number = filter_var($limit, \FILTER_VALIDATE_INT);
        if (false === $number) {
            throw new \InvalidArgumentException('Лимит должен быть целым числом.');
        }

        return $number;
    }
}
