<?php

declare(strict_types=1);

namespace App\Support\Retry\Application;

use Symfony\Component\Clock\DatePoint;

interface RetryableFailure extends \Throwable
{
    /**
     * Рекомендуемая нижняя граница повтора потенциально временного отказа.
     * null не запрещает повтор. Момент не гарантирует успеха и не резервирует ресурсы.
     */
    public function retryAt(): ?DatePoint;
}
