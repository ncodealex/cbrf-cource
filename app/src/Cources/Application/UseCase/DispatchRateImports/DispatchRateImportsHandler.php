<?php

declare(strict_types=1);

namespace App\Cources\Application\UseCase\DispatchRateImports;

use App\Cources\Application\Contract\RateImportJobPublisherInterface;
use App\Cources\Application\Contract\RateImportJobRepositoryInterface;
use App\Cources\Application\Import\RateImportSettings;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class DispatchRateImportsHandler
{
    public function __construct(
        private RateImportJobRepositoryInterface $repository,
        private RateImportJobPublisherInterface $publisher,
        private RateImportSettings $settings,
        #[Autowire(service: 'monolog.logger.import_dispatcher')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): int
    {
        $messages = $this->repository->reserveDispatch($this->settings->batchSize, $this->settings->redispatchAfter, $this->settings->maxUnclaimed);
        $sent = 0;
        foreach ($messages as $message) {
            try {
                $this->publisher->publish($message);
                ++$sent;
            } catch (\Throwable $e) {
                $this->logger->error('Не удалось опубликовать задание импорта.', ['requested_date' => $message->requestedDate, 'version' => $message->version, 'exception_class' => $e::class]);
            }
        }
        if ([] !== $messages) {
            $this->logger->info('Диспетчеризация заданий импорта.', ['reserved' => \count($messages), 'published' => $sent]);
        }

        return $sent;
    }
}
