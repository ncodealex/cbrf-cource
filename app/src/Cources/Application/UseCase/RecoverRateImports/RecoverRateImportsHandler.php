<?php

declare(strict_types=1);

namespace App\Cources\Application\UseCase\RecoverRateImports;

use App\Cources\Application\Contract\RateImportJobRepositoryInterface;
use App\Cources\Application\Exception\RateSetLoadBusy;
use App\Cources\Application\Import\RateImportSettings;
use App\Support\Retry\Application\RetryPolicy;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class RecoverRateImportsHandler
{
    public function __construct(
        private RateImportJobRepositoryInterface $repository,
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
        foreach ($this->repository->expired($this->settings->batchSize) as $claim) {
            $attempts = $claim->attempts + 1;
            $decision = $this->retryPolicy->decide(new RateSetLoadBusy('Истёк срок владения заданием.'), $attempts);
            if ($this->repository->recover($claim, $attempts, $this->repository->isConfirmed($claim->requestedDate) ? DatePoint::createFromInterface($this->clock->now()) : $decision->retryAt)) {
                ++$count;
            }
        }
        if ($count > 0) {
            $this->logger->warning('Восстановлены задания с истёкшим lease.', ['count' => $count]);
        }

        return $count;
    }
}
