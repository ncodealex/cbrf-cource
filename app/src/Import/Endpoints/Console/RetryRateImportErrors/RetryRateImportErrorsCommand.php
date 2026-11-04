<?php

declare(strict_types=1);

namespace App\Import\Endpoints\Console\RetryRateImportErrors;

use App\Import\Application\UseCase\RetryRateImportErrors\RetryRateImportErrorsHandler;
use App\Import\Domain\Exception\InvalidRateImportTransition;
use App\Shared\Domain\DateFormatInterface;
use App\Shared\Domain\DatePointParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:rates:retry-errors', description: 'Вернуть ошибочные задания импорта в Waiting')]
final readonly class RetryRateImportErrorsCommand
{
    public function __construct(
        private RetryRateImportErrorsHandler $handler,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Option]
        ?string $date = null,
        #[Option]
        bool $all = false,
        #[Option]
        bool $yes = false,
    ): int {
        if ($all === (null !== $date)) {
            $io->error('Укажите либо --date, либо --all.');

            return Command::INVALID;
        }
        if ($all && !$yes) {
            if (!$input->isInteractive()) {
                $io->error('Для массового повтора без взаимодействия требуется --yes.');

                return Command::INVALID;
            }
            if (!$io->confirm('Вернуть все Error в Waiting?', false)) {
                return Command::SUCCESS;
            }
        }
        try {
            $count = ($this->handler)(null === $date ? null : DatePointParser::parse($date)->format(DateFormatInterface::ISO));
        } catch (\InvalidArgumentException|InvalidRateImportTransition $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
        if (0 === $count) {
            $io->text('Нет восстановленных заданий: ошибки отсутствуют либо состояние изменилось конкурентно.');
        } else {
            $io->success(\sprintf('Возвращено в Waiting: %d. Дальнейшая обработка выполняется Dispatcher.', $count));
        }

        return Command::SUCCESS;
    }
}
