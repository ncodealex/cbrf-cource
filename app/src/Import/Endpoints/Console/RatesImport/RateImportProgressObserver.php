<?php

declare(strict_types=1);

namespace App\Import\Endpoints\Console\RatesImport;

use App\Import\Application\Configuration\RateImportSettings;
use App\Import\Application\UseCase\GetRateImportProgress\GetRateImportProgressHandler;
use App\Import\Domain\ValueObject\RatesImportPeriod;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Периодически показывает прогресс импорта до завершения, остановки или истечения времени ожидания.
 */
final readonly class RateImportProgressObserver
{
    public function __construct(
        private GetRateImportProgressHandler $handler,
        private ClockInterface $clock,
        private RateImportSettings $settings,
    ) {
    }

    public function observe(
        RatesImportPeriod $period,
        OutputInterface $output,
        ?int $interval,
        ?int $timeout,
    ): int {
        $interval ??= $this->settings->progressInterval;
        $timeout ??= $this->settings->progressTimeout;
        if ($interval < 1 || $timeout < 0) {
            throw new \InvalidArgumentException('Некорректный интервал или timeout наблюдения.');
        }
        $start = $this->clock->now()->getTimestamp();
        $bar = $output->isDecorated() ? new ProgressBar($output, $period->count()) : null;
        if (null !== $bar) {
            $bar->setFormat("%current%/%max% [%bar%] %percent:3s%%\nОжидает: %waiting% | Обработка: %processing% | Отложено: %deferred% | Ошибки: %errors%\nНекорректных Done: %inconsistent% | Без задания: %missing%\nПрошло: %elapsed_time% | ETA: %remaining_time%");
            $bar->setBarWidth(25);
            $bar->setOverwrite(true);
        }
        $initialConfirmed = null;
        $notice = null;
        $previous = null;
        $stopping = false;
        $handlers = [];
        if (\function_exists('pcntl_signal')) {
            foreach ([\SIGINT, \SIGTERM] as $signal) {
                $handlers[$signal] = pcntl_signal_get_handler($signal);
                pcntl_signal($signal, static function () use (&$stopping): void { $stopping = true; });
            }
        }
        try {
            while (true) {
                if (\function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
                if ($stopping) {
                    return Command::SUCCESS;
                }
                $elapsed = $this->clock->now()->getTimestamp() - $start;
                $snapshot = ($this->handler)($period, $elapsed, $initialConfirmed ?? 0);
                $initialConfirmed ??= $snapshot->confirmed;
                $eta = null === $snapshot->estimatedRemaining ? '—' : $this->duration($snapshot->estimatedRemaining);
                if (null !== $bar) {
                    foreach (['waiting' => $snapshot->waiting, 'processing' => $snapshot->processing, 'deferred' => $snapshot->deferred, 'errors' => $snapshot->error, 'inconsistent' => $snapshot->inconsistentDone, 'missing' => $snapshot->missing] as $name => $value) {
                        $bar->setMessage((string) $value, $name);
                    }
                    $bar->setMessage($this->duration($elapsed), 'elapsed_time');
                    $bar->setMessage($eta, 'remaining_time');
                    $bar->setProgress($snapshot->confirmed);
                    $bar->display();
                } elseif ($snapshot->counts() !== $previous) {
                    $output->writeln(\sprintf('Подтверждено: %d/%d; Waiting: %d; Processing: %d; Deferred: %d; Error: %d; некорректных Done: %d; без задания: %d; прошло: %s; ETA: %s', $snapshot->confirmed, $period->count(), $snapshot->waiting, $snapshot->processing, $snapshot->deferred, $snapshot->error, $snapshot->inconsistentDone, $snapshot->missing, $this->duration($elapsed), $eta));
                    $previous = $snapshot->counts();
                }
                if ($snapshot->confirmed === $period->count()) {
                    $bar?->finish();

                    return Command::SUCCESS;
                }
                if (0 === $snapshot->waiting + $snapshot->processing + $snapshot->deferred) {
                    return Command::FAILURE;
                }
                if ($timeout > 0 && $elapsed >= $timeout) {
                    $notice = 'Время наблюдения истекло. Импорт продолжается.';

                    return Command::FAILURE;
                }
                $this->clock->sleep($interval);
            }
        } finally {
            if (null !== $bar) {
                $output->writeln('');
            }
            if (null !== $notice) {
                $output->writeln($notice);
            }
            foreach ($handlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
        }
    }

    private function duration(int $seconds): string
    {
        return \sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
