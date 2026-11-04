<?php

declare(strict_types=1);

namespace App\Import\Application\UseCase\RecoverRateImports;

use App\Import\Application\Configuration\RateImportSettings;
use App\Import\Application\Contract\ImportJobQueryRepositoryInterface;
use App\Import\Application\Contract\ImportJobRepositoryInterface;
use App\Import\Application\Exception\ExpiredImportLease;
use App\Shared\Domain\DateFormatInterface;
use App\Support\Retry\Application\RetryPolicy;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class RecoverRateImportsHandler
{
    public function __construct(
        private ImportJobRepositoryInterface $repository,
        private ImportJobQueryRepositoryInterface $queries,
        private RateImportSettings $settings,
        #[Autowire(service: 'app.import.retry_policy')]
        private RetryPolicy $retryPolicy,
        #[Autowire(service: 'monolog.logger.import_dispatcher')]
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(): int
    {
        $count = 0;
        foreach ($this->queries->expired($this->settings->batchSize) as $claim) {
            $attempts = $claim->attempts + 1;
            $decision = $this->retryPolicy->decide(new ExpiredImportLease('Истёк срок владения заданием.'), $attempts);
            if ($this->repository->recover($claim, $attempts, $this->queries->isConfirmed($claim->requestedDate->format(DateFormatInterface::ISO)) ? DatePoint::createFromInterface($this->clock->now()) : $decision->retryAt)) {
                ++$count;
            }
        }
        if ($count > 0) {
            $this->logger->warning('Восстановлены задания с истёкшим lease.', ['count' => $count]);
        }

        return $count;
    }
}
