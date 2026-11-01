<?php
declare(strict_types=1);

namespace App\Cources\Endpoints\Console\GetExchangeRate;

use App\Cources\Application\GetExchangeRate\GetExchangeRateResult;
use App\Shared\Domain\DateFormatInterface;

final class GetExchangeRateCommandJsonResponse
{
    public static function data(GetExchangeRateResult $result): array
    {
        return [
            'requestedDate'         => $result->requestedDate->format(DateFormatInterface::UI),
            'currency'              => (string)$result->currency,
            'base'                  => (string)$result->base,
            'currencyName'          => $result->currencyName,
            'nominal'               => $result->nominal,
            'sourceValue'           => (string)$result->sourceValue,
            'effectiveDate'         => $result->effectiveDate->format(DateFormatInterface::UI),
            'rate'                  => (string)$result->rate,
            'previousEffectiveDate' => $result->previousEffectiveDate->format(DateFormatInterface::UI),
            'previousRate'          => (string)$result->previousRate,
            'diff'                  => (string)$result->diff,
        ];
    }
}
