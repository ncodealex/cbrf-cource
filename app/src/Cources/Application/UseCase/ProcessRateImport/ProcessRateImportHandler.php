<?php

declare(strict_types=1);

namespace App\Cources\Application\UseCase\ProcessRateImport;

use App\Cources\Application\Contract\RateImportJobRepositoryInterface;
use App\Cources\Application\Contract\RateImportPermanentFailure;
use App\Cources\Application\Contract\RateSetProviderInterface;
use App\Cources\Application\Import\RateImportAction;
use App\Cources\Application\Import\RateImportIncident;
use App\Cources\Application\Import\RateImportSettings;
use App\Cources\Application\Message\ProcessRateImportMessage;
use App\Shared\Domain\DatePointParser;
use App\Support\Retry\Application\AdmissionFailure;
use App\Support\Retry\Application\RetryableFailure;
use App\Support\Retry\Application\RetryDecision;
use App\Support\Retry\Application\RetryPolicy;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ProcessRateImportHandler
{
    public function __construct(
        private RateImportJobRepositoryInterface $repository,
        private RateSetProviderInterface $provider,
        private RateImportSettings $settings,
        #[Autowire(service: 'app.import.retry_policy')]
        private RetryPolicy $retryPolicy,
        #[Autowire(service: 'monolog.logger.import_worker')]
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ProcessRateImportMessage $message): void
    {
        $claim = $this->repository->claim($message->requestedDate, $message->version, $this->settings->leaseSeconds);
        if (null === $claim) {
            return;
        }
        $this->logger->info('Задание импорта захвачено.', ['requested_date' => $claim->requestedDate, 'version' => $claim->version]);
        $attempts = $claim->attempts;
        // Ошибки чтения/записи PostgreSQL выходят в Messenger, не маскируются прикладным Error.
        if (!$this->repository->isConfirmed($claim->requestedDate)) {
            $failure = null;
            try {
                $this->provider->resolve(DatePointParser::parse($claim->requestedDate));
                ++$attempts;
            } catch (\Throwable $e) {
                $failure = $e;
            }
            if (null !== $failure) {
                if (!$failure instanceof RetryableFailure && !$failure instanceof RateImportPermanentFailure) {
                    throw $failure;
                }
                if (!$failure instanceof AdmissionFailure) {
                    ++$attempts;
                }
                $decision = $failure instanceof AdmissionFailure
                    ? new RetryDecision(true, $failure->retryAt() ?? DatePoint::createFromInterface($this->clock->now())->modify('+1 second'))
                    : $this->retryPolicy->decide($failure, $attempts);
                $code = RateImportIncident::create();
                $action = $decision->allowed ? RateImportAction::Defer : RateImportAction::Fail;
                $saved = $this->repository->finish($claim, $action, $attempts, $decision->retryAt, $code, $failure->getMessage());
                $this->logger->log($decision->allowed ? 'notice' : 'error', 'Обработка задания импорта завершилась ошибкой.', ['requested_date' => $claim->requestedDate, 'version' => $claim->version, 'action' => $action->name, 'saved' => $saved, 'error_code' => $code, 'exception_class' => $failure::class]);

                return;
            }
            if (!$this->repository->isConfirmed($claim->requestedDate)) {
                throw new \RuntimeException('Источник не подтвердил календарную дату в PostgreSQL.');
            }
        }
        $saved = $this->repository->finish($claim, RateImportAction::Complete, $attempts);
        $this->logger->info('Завершение задания импорта.', ['requested_date' => $claim->requestedDate, 'version' => $claim->version, 'saved' => $saved]);
    }
}
