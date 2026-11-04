<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Messenger;

use App\Cources\Application\Contract\RateImportJobPublisherInterface;
use App\Cources\Application\Message\ProcessRateImportMessage;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Messenger\MessageBusInterface;

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
