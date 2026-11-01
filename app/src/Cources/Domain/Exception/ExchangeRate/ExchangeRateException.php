<?php

declare(strict_types=1);

namespace App\Cources\Domain\Exception\ExchangeRate;

use App\Shared\Domain\Exception\SharedDomainException;

/** Общий тип ошибок расчёта курса. */
abstract class ExchangeRateException extends SharedDomainException
{
}
