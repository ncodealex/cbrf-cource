<?php

declare(strict_types=1);

namespace App\Cources\Endpoints\Console\RatesImport;

use App\Cources\Application\Exception\InvalidRatesImportPeriod;
use App\Cources\Application\Factory\RatesImportPeriodFactory;
use App\Cources\Application\Import\RateImportStatus;
use App\Cources\Application\UseCase\RatesImport\RatesImportHandler;
use App\Shared\Domain\DateFormatInterface;
use App\Shared\Domain\DatePointParser;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use function sprintf;
use const FILTER_VALIDATE_INT;

#[AsCommand(name: 'app:rates:import', description: 'Зарегистрировать задания импорта исторических котировок ЦБ РФ')]
final readonly class RatesImportCommand
{
    public function __construct(
        private RatesImportPeriodFactory $factory,
        private RatesImportHandler $handler
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Дата начала периода')]
        ?string $from = null,
        #[Option(description: 'Дата окончания периода')]
        ?string $to = null,
        #[Option(description: 'Число календарных дней')]
        ?string $days = null,
    ): int {
        try {
            $numberOfDays = null;
            if (null !== $days) {
                if (!preg_match('/\A[+-]?[0-9]+\z/', $days)) {
                    throw new InvalidRatesImportPeriod('Число дней должно быть целым числом.');
                }
                $numberOfDays = filter_var($days, FILTER_VALIDATE_INT);
                if (false === $numberOfDays) {
                    throw new InvalidRatesImportPeriod('Число дней должно быть целым числом в допустимом диапазоне.');
                }
            }
            $period = $this->factory->create(
                null === $from ? null : DatePointParser::parse($from),
                null === $to ? null : DatePointParser::parse($to),
                $numberOfDays,
            );
        } catch (InvalidArgumentException $e) {
            $io->getErrorStyle()->error($e->getMessage());

            return Command::INVALID;
        }

        try {
            $result = ($this->handler)($period);
        } catch (Throwable $e) {
            $io->getErrorStyle()->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->title('Импорт исторических котировок ЦБ РФ');
        $io->definitionList(
            [
                'Период' => sprintf('%s — %s', $period->from->format(DateFormatInterface::UI),
                    $period->to->format(DateFormatInterface::UI)),
            ],
            ['Календарных дней' => $result->total],
        );
        $io->section('Регистрация');
        $io->definitionList(
            ['Уже загружено' => $result->confirmed],
            ['Уже зарегистрировано' => $result->registered],
            ['Создано новых' => $result->created],
        );
        $io->section('Текущее состояние');
        foreach (RateImportStatus::cases() as $status) {
            $io->definitionList([$status->label() => $result->states[$status->value]]);
        }
        $io->success('Задания зарегистрированы.');
        $io->text('Обработка RabbitMQ будет подключена на следующем этапе.');

        return Command::SUCCESS;
    }
}
