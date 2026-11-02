<?php

declare(strict_types=1);

namespace App\Cources\Application\Contract;

/** Ошибка источника или данных, которую бессмысленно повторять автоматически. */
interface RateImportPermanentFailure extends \Throwable
{
}
