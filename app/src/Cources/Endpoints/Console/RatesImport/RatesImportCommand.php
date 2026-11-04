<?php

declare(strict_types=1);

namespace App\Cources\Endpoints\Console\RatesImport;

use App\Cources\Application\Exception\InvalidRatesImportPeriod;
use App\Cources\Application\Factory\RatesImportPeriodFactory;
use App\Cources\Application\Import\RateImportStatus;
use App\Cources\Application\Import\RatesImportPeriodResolution;
use App\Cources\Application\UseCase\RatesImport\RatesImportHandler;
use App\Shared\Domain\DateFormatInterface;
use App\Shared\Domain\DatePointParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:rates:import', description: 'Зарегистрировать задания импорта исторических котировок ЦБ РФ')]
final readonly class RatesImportCommand
{
    public function __construct(
        private RatesImportPeriodFactory $factory,
        private RatesImportHandler $handler,
        private ?RateImportProgressObserver $observer = null
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        OutputInterface $output,
        #[Option(description: 'Дата начала периода')]
        ?string $from = null,
        #[Option(description: 'Дата окончания периода')]
        ?string $to = null,
        #[Option(description: 'Число календарных дней')]
        ?string $days = null,
        #[Option(description: 'Подтвердить корректировку периода без вопроса')]
        bool $yes = false,
        #[Option]
        bool $progress = false,
        #[Option]
        ?int $progressInterval = null,
        #[Option]
        ?int $progressTimeout = null,
    ): int {
        try {
            if ((null !== $progressInterval && $progressInterval < 1) || (null !== $progressTimeout && $progressTimeout < 0)) {
                throw new \InvalidArgumentException('Некорректный интервал или timeout наблюдения.');
            }
            $numberOfDays = null;
            if (null !== $days) {
                if (!preg_match('/\A[+-]?[0-9]+\z/', $days)) {
                    throw new InvalidRatesImportPeriod('Число дней должно быть целым числом.');
                }
                $numberOfDays = filter_var($days, \FILTER_VALIDATE_INT);
                if (false === $numberOfDays) {
                    throw new InvalidRatesImportPeriod('Число дней должно быть целым числом в допустимом диапазоне.');
                }
            }
            $resolution = $this->factory->create(
                null === $from ? null : DatePointParser::parse($from),
                null === $to ? null : DatePointParser::parse($to),
                $numberOfDays,
            );
        } catch (\InvalidArgumentException $e) {
            $io->getErrorStyle()->error($e->getMessage());

            return Command::INVALID;
        }

        if ($resolution->wasAdjusted()) {
            $this->displayAdjustment($io, $resolution);
            if (!$yes) {
                if (!$input->isInteractive()) {
                    $io->getErrorStyle()->error('Для корректировки периода требуется подтверждение. Добавьте --yes либо запустите команду интерактивно.');

                    return Command::INVALID;
                }
                if (!$io->confirm('Продолжить?', false)) {
                    $io->text('Регистрация заданий отменена.');

                    return Command::SUCCESS;
                }
            }
        }
        $period = $resolution->period;
        try {
            $result = ($this->handler)($period);
        } catch (\Throwable $e) {
            $io->getErrorStyle()->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->title('Импорт исторических котировок ЦБ РФ');
        $io->definitionList(
            [
                'Период' => \sprintf(
                    '%s — %s',
                    $period->from->format(DateFormatInterface::UI),
                    $period->to->format(DateFormatInterface::UI)
                ),
            ],
            ['Календарных дней' => $result->total],
        );
        $io->section('Регистрация');
        $io->definitionList(
            ['Уже загружено' => $result->confirmed],
            ['Уже зарегистрировано' => $result->registered],
            ['Создано новых' => $result->created],
        );
        if ($result->requeuedDone > 0) {
            $io->definitionList(['Восстановлено Done' => $result->requeuedDone]);
        }
        $io->section('Текущее состояние');
        foreach (RateImportStatus::cases() as $status) {
            $io->definitionList([$status->label() => $result->statistics->get($status)]);
        }
        $io->success('Задания зарегистрированы.');
        if ($progress && null !== $this->observer) {
            return $this->observer->observe($period, $output, $progressInterval, $progressTimeout);
        }
        $io->text('Обработка выполняется Dispatcher и Messenger Worker.');

        return Command::SUCCESS;
    }

    private function displayAdjustment(SymfonyStyle $io, RatesImportPeriodResolution $resolution): void
    {
        if (null !== $resolution->requestedDays) {
            if (null !== $resolution->requestedFrom) {
                $io->text(\sprintf('Исходный запрос: %d календарных дней начиная с %s.', $resolution->requestedDays, $resolution->requestedFrom->format(DateFormatInterface::UI)));
            } else {
                $io->text(\sprintf('Исходный запрос: %d календарных дней по %s включительно.', $resolution->requestedDays, $resolution->requestedTo?->format(DateFormatInterface::UI)));
            }
        } else {
            $io->text(\sprintf('Выбранный период: %s — %s', $resolution->requestedFrom?->format(DateFormatInterface::UI), $resolution->requestedTo?->format(DateFormatInterface::UI)));
        }
        if ($resolution->fromAdjusted) {
            $io->text(\sprintf('Начальная дата выходит за допустимый диапазон. Минимальная доступная дата: %s.', $resolution->period->from->format(DateFormatInterface::UI)));
        }
        if ($resolution->toAdjusted) {
            $io->text(\sprintf('Конечная дата выходит за допустимый диапазон. Максимальная доступная дата: %s.', $resolution->period->to->format(DateFormatInterface::UI)));
        }
        $io->text(\sprintf('Будет использован период: %s — %s', $resolution->period->from->format(DateFormatInterface::UI), $resolution->period->to->format(DateFormatInterface::UI)));
    }
}
