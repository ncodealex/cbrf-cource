<?php

declare(strict_types=1);

namespace App\Cources\Endpoints\Console\RefreshRateSet;

use App\Cources\Application\UseCase\RefreshRateSet\RefreshRateSetHandler;
use App\Shared\Domain\DateFormatInterface;
use App\Shared\Domain\DatePointParser;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Удаляет сохранённый набор курсов для даты и загружает его повторно через сценарий refresh.
 */
#[AsCommand(name: 'app:rates:refresh', description: 'Удалить и заново загрузить курсы для одной календарной даты')]
final readonly class RefreshRateSetCommand
{
    public function __construct(
        private RefreshRateSetHandler $handler,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Календарная дата для повторной загрузки')]
        ?string $date = null,
    ): int {
        try {
            $requestDate = $this->validateDate($date);
        } catch (\InvalidArgumentException $e) {
            $io->getErrorStyle()->error($e->getMessage());

            return Command::INVALID;
        }

        try {
            $rateSet = ($this->handler)($requestDate);
        } catch (\Throwable $e) {
            $io->getErrorStyle()->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            'Курсы для даты %s обновлены: дата действия %s, валют: %d.',
            $requestDate->format(DateFormatInterface::UI),
            $rateSet->effectiveDate->format(DateFormatInterface::UI),
            \count($rateSet->items),
        ));

        return Command::SUCCESS;
    }

    private function validateDate(?string $date): DatePoint
    {
        if (null === $date) {
            throw new \InvalidArgumentException('Укажите дату в опции --date.');
        }

        return DatePointParser::parse($date);
    }
}
