<?php

declare(strict_types=1);

namespace App\Support\Retry\Application;

/**
 * Отказ до начала внешней операции; не расходует бюджет попыток импорта.
 */
interface AdmissionFailure extends RetryableFailure
{
}
