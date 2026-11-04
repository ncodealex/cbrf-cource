<?php

declare(strict_types=1);

namespace App\Import\Endpoints\Console\DispatchRateImports;

use App\Import\Application\Configuration\RateImportSettings;
use App\Import\Application\UseCase\DispatchRateImports\DispatchRateImportsHandler;
use App\Import\Application\UseCase\RecoverRateImports\RecoverRateImportsHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Восстанавливает задания с истёкшим lease и публикует доступные задания однократно или в цикле.
 */
#[AsCommand(name: 'app:rates:dispatch', description: 'Публикация заданий импорта и восстановление истёкших lease')]
final class DispatchRateImportsCommand
{
    private bool $stopping = false;

    public function __construct(
        private readonly DispatchRateImportsHandler $dispatch,
        private readonly RecoverRateImportsHandler $recover,
        private readonly RateImportSettings $settings,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'monolog.logger.import_dispatcher')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @phpstan-impure
     */
    private function isStopping(): bool
    {
        if (\function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        return $this->stopping;
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option]
        bool $once = false,
        #[Option]
        bool $watch = false,
    ): int {
        if (!$this->validMode($once, $watch)) {
            $io->error('Укажите один режим: --once или --watch.');

            return Command::INVALID;
        }
        $this->stopping = false;
        $previous = [];
        if ($watch && \function_exists('pcntl_signal')) {
            foreach ([\SIGTERM, \SIGINT] as $signal) {
                $previous[$signal] = pcntl_signal_get_handler($signal);
                pcntl_signal($signal, function (): void { $this->stopping = true; });
            }
        }
        try {
            do {
                if (\function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
                if ($this->isStopping()) {
                    break;
                }
                try {
                    ($this->recover)();
                    ($this->dispatch)();
                } catch (\Throwable $e) {
                    $this->logger->error('Сбой прохода диспетчера импорта.', ['exception_class' => $e::class]);
                    if ($once) {
                        $io->error('Сбой диспетчера; подробности в журнале.');

                        return Command::FAILURE;
                    }
                }
                if ($watch && !$this->isStopping()) {
                    $this->clock->sleep($this->settings->interval);
                }
            } while ($watch && !$this->isStopping());
        } finally {
            foreach ($previous as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
        }

        return Command::SUCCESS;
    }

    private function validMode(bool $once, bool $watch): bool
    {
        return $once !== $watch;
    }
}
