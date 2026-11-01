<?php

declare(strict_types=1);

namespace App\Cources\Endpoints\Console\GetExchangeRate;

use App\Cources\Application\Exception\RateSetConflict;
use App\Cources\Application\Exception\RateSetNotFound;
use App\Cources\Application\GetExchangeRate\GetExchangeRateHandler;
use App\Cources\Application\GetExchangeRate\GetExchangeRateQuery;
use App\Cources\Application\GetExchangeRate\GetExchangeRateResult;
use App\Cources\Domain\Exception\ExchangeRate\ExchangeRateException;
use App\Cources\Domain\Factory\CurrencyPairFactoryInterface;
use App\Cources\Infrastructure\Cbr\Exception\CbrGatewayException;
use App\Shared\Domain\DateFormatInterface;
use App\Shared\Domain\DatePointParser;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'app:rates:get', description: 'Получить официальный курс валюты и его изменение')]
final readonly class GetExchangeRateCommand
{

    public const string RENDER_FORMAT_TABLE = 'table';

    public const string RENDER_FORMAT_JSON = 'json';


    public function __construct(
        private GetExchangeRateHandler $handler,
        private CurrencyPairFactoryInterface $currencyPairFactory,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Код валюты', name: 'currency')] string $currency,
        #[Option(description: 'Базовая валюта')] string $base = 'RUR',
        #[Option(description: 'Дата курса')] ?string $date = null,
        #[Option(description: 'Формат: table или json')] string $format = self::RENDER_FORMAT_TABLE,
    ): int {
        try {
            if (!in_array($format, [self::RENDER_FORMAT_TABLE, self::RENDER_FORMAT_JSON], true)) {
                throw new InvalidArgumentException(
                    sprintf('Формат должен быть %s или %s.',
                        self::RENDER_FORMAT_TABLE,
                        self::RENDER_FORMAT_JSON)
                );
            }
            $pair          = $this->currencyPairFactory->of($currency, $base);
            $today         = $this->clock->now();
            $requestedDate = DatePointParser::parse($date ?? $today->format(DateFormatInterface::ISO));
        } catch (Throwable $e) {
            $io->getErrorStyle()->error($e->getMessage());
            return Command::INVALID;
        }

        try {
            $result = ($this->handler)(new GetExchangeRateQuery($requestedDate, $pair));
        } catch (RateSetNotFound|RateSetConflict|CbrGatewayException|ExchangeRateException $e) {
            $io->getErrorStyle()->error($e->getMessage());
            return Command::FAILURE;
        }

        if (self::RENDER_FORMAT_JSON === $format) {
            $this->renderJson($io, $result);
        } else {
            $this->renderTable($io, $result);
        }

        return Command::SUCCESS;
    }

    private function renderJson(SymfonyStyle $io, GetExchangeRateResult $result): void
    {
        $data = GetExchangeRateCommandJsonResponse::data($result);
        $io->writeln(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function renderTable(SymfonyStyle $io, GetExchangeRateResult $result): void
    {
        $io->title('КУРС ВАЛЮТ ЦБ РФ');
        $io->text('Валютная пара');
        $io->definitionList(
            ['Пара' => $result->currency . ' / ' . $result->base],
            ['Наименование' => $result->currencyName],
            ['Номинал ЦБ' => $result->nominal . ' ' . $result->currency],
            ['Котировка ЦБ' => $result->sourceValue . ' RUB / ' . $result->nominal . ' ' . $result->currency],
        );

        $io->text('Расчёт курса');
        $io->definitionList(
            ['Дата запроса' => $result->requestedDate->format(DateFormatInterface::UI)],
            ['Дата действия' => $result->effectiveDate->format(DateFormatInterface::UI)],
            ['Текущий курс' => $result->rate . ' ' . $result->base],
            ['Предыдущая дата' => $result->previousEffectiveDate->format(DateFormatInterface::UI)],
            ['Предыдущий курс' => $result->previousRate . ' ' . $result->base],
            ['Изменение' => (($result->diff->compare(0) > 0 ? '+' : '') . $result->diff) . ' ' . $result->base],
        );
    }
}
