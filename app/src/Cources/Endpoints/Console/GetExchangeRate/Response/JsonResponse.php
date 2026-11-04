<?php

declare(strict_types=1);

namespace App\Cources\Endpoints\Console\GetExchangeRate\Response;

use App\Cources\Application\UseCase\GetExchangeRate\GetExchangeRateResult;
use App\Shared\Domain\DateFormatInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class JsonResponse
{
    /**
     * @return array<string, int|string>
     */
    public static function data(GetExchangeRateResult $result): array
    {
        return [
            'requestedDate' => $result->requestedDate->format(DateFormatInterface::UI),
            'currency' => (string) $result->currency,
            'base' => (string) $result->base,
            'currencyName' => $result->currencyName,
            'nominal' => $result->nominal,
            'sourceValue' => (string) $result->sourceValue,
            'effectiveDate' => $result->effectiveDate->format(DateFormatInterface::UI),
            'rate' => (string) $result->rate,
            'previousEffectiveDate' => $result->previousEffectiveDate->format(DateFormatInterface::UI),
            'previousRate' => (string) $result->previousRate,
            'diff' => (string) $result->diff,
        ];
    }
}
