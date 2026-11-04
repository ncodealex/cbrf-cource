<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Messenger;

use App\Import\Application\Contract\RateImportJobPublisherInterface;
use App\Import\Application\Message\ProcessRateImportMessage;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Публикует сообщение о зарезервированном задании через шину Messenger.
 */
#[AsAlias(RateImportJobPublisherInterface::class)]
final readonly class MessengerRateImportJobPublisher implements RateImportJobPublisherInterface
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function publish(ProcessRateImportMessage $message): void
    {
        $this->bus->dispatch($message);
    }
}
