<?php

declare(strict_types=1);

namespace App\Cources\Application\UseCase\GetExchangeRate;

use App\Cources\Domain\CurrencyPair;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Вход сценария: календарная дата запроса и валютная пара для расчёта.
 */
#[Exclude]
final readonly class GetExchangeRateQuery
{
    public function __construct(
        public DatePoint $requestedDate,
        public CurrencyPair $pair,
    ) {
    }
}
