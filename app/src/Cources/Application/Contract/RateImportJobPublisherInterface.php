<?php

declare(strict_types=1);

namespace App\Cources\Application\Contract;

use App\Cources\Application\Message\ProcessRateImportMessage;

interface RateImportJobPublisherInterface
{
    public function publish(ProcessRateImportMessage $message): void;
}
