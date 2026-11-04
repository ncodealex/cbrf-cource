<?php

declare(strict_types=1);

namespace App\Import\Application\Contract;

use App\Import\Application\Message\ProcessRateImportMessage;

interface RateImportJobPublisherInterface
{
    /**
     * Опубликовать дату и версию задания в существующий транспорт rates_import.
     * Доставка допускает повторы; публикация не передаёт Worker право владения заданием.
     *
     * @throws \Throwable если транспорт не принял сообщение
     */
    public function publish(ProcessRateImportMessage $message): void;
}
