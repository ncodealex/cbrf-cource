<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Rates;

/**
 * Ошибка источника или данных, которую бессмысленно повторять автоматически.
 */
interface RateImportPermanentFailure extends \Throwable
{
}
