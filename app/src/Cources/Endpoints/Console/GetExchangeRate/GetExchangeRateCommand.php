<?php

declare(strict_types=1);

namespace App\Cources\Endpoints\Console\GetExchangeRate;

use App\Cources\Application\UseCase\GetExchangeRate\GetExchangeRateQuery;
use App\Cources\Application\UseCase\GetExchangeRate\GetExchangeRateResult;
use App\Cources\Domain\Factory\CurrencyPairFactoryInterface;
use App\Cources\Endpoints\Console\GetExchangeRate\Response\JsonResponse;
use App\Shared\Domain\DateFormatInterface;
use App\Shared\Domain\DatePointParser;
use App\Support\Retry\Application\RetryableFailure;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Получает курс валютной пары и выводит текущую котировку, предыдущий курс и изменение.
 */
#[AsCommand(name: 'app:rates:get', description: 'Получить официальный курс валюты и его изменение')]
final readonly class GetExchangeRateCommand
{
    public const string RENDER_FORMAT_TABLE = 'table';

    public const string RENDER_FORMAT_JSON = 'json';

    public function __construct(
        private RetryingGetExchangeRate $handler,
        private CurrencyPairFactoryInterface $currencyPairFactory,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Код валюты', name: 'currency')]
        string $currency,
        #[Option(description: 'Базовая валюта')]
        string $base = 'RUR',
        #[Option(description: 'Дата курса')]
        ?string $date = null,
        #[Option(description: 'Формат: table или json')]
        string $format = self::RENDER_FORMAT_TABLE,
    ): int {
        try {
            $query = $this->validateInput($currency, $base, $date, $format);
        } catch (\Throwable $e) {
            $io->getErrorStyle()->error($e->getMessage());

            return Command::INVALID;
        }

        try {
            $result = ($this->handler)($query);
        } catch (RetryableFailure $e) {
            $message = $e->getMessage();
            if (null !== ($retryAt = $e->retryAt())) {
                $message .= ' Повтор возможен не раньше '.$retryAt->setTimezone(DatePointParser::timezone())->format(DateFormatInterface::UI.' H:i:s T').'.';
            }
            $io->getErrorStyle()->error($message);

            return Command::FAILURE;
        } catch (\Throwable $e) {
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

    private function validateInput(
        string $currency,
        string $base,
        ?string $date,
        string $format,
    ): GetExchangeRateQuery {
        if (!\in_array($format, [self::RENDER_FORMAT_TABLE, self::RENDER_FORMAT_JSON], true)) {
            throw new \InvalidArgumentException(\sprintf('Формат должен быть %s или %s.', self::RENDER_FORMAT_TABLE, self::RENDER_FORMAT_JSON));
        }

        $pair = $this->currencyPairFactory->of($currency, $base);
        $today = $this->clock->now();
        $requestedDate = DatePointParser::parse($date ?? $today->format(DateFormatInterface::ISO));

        return new GetExchangeRateQuery($requestedDate, $pair);
    }

    private function renderJson(
        SymfonyStyle $io,
        GetExchangeRateResult $result,
    ): void {
        $data = JsonResponse::data($result);
        $io->writeln(json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT));
    }

    private function renderTable(
        SymfonyStyle $io,
        GetExchangeRateResult $result,
    ): void {
        $io->title('КУРС ВАЛЮТ ЦБ РФ');
        $io->text('Валютная пара');
        $io->definitionList(
            ['Пара' => $result->currency.' / '.$result->base],
            ['Наименование' => $result->currencyName],
            ['Номинал ЦБ' => $result->nominal.' '.$result->currency],
            ['Котировка ЦБ' => $result->sourceValue.' RUB / '.$result->nominal.' '.$result->currency],
        );

        $io->text('Расчёт курса');
        $io->definitionList(
            ['Дата запроса' => $result->requestedDate->format(DateFormatInterface::UI)],
            ['Дата действия' => $result->effectiveDate->format(DateFormatInterface::UI)],
            ['Текущий курс' => $result->rate.' '.$result->base],
            ['Предыдущая дата' => $result->previousEffectiveDate->format(DateFormatInterface::UI)],
            ['Предыдущий курс' => $result->previousRate.' '.$result->base],
            ['Изменение' => (($result->diff->compare(0) > 0 ? '+' : '').$result->diff).' '.$result->base],
        );
    }
}
