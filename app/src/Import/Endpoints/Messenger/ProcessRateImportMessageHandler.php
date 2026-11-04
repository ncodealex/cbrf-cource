<?php

declare(strict_types=1);

namespace App\Import\Endpoints\Messenger;

use App\Import\Application\Message\ProcessRateImportMessage;
use App\Import\Application\UseCase\ProcessRateImport\ProcessRateImportHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'rates_import')]
final readonly class ProcessRateImportMessageHandler
{
    public function __construct(
        private ProcessRateImportHandler $handler,
    ) {
    }

    public function __invoke(ProcessRateImportMessage $message): void
    {
        ($this->handler)($message);
    }
}
