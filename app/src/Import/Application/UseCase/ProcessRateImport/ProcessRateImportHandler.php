<?php

declare(strict_types=1);

namespace App\Import\Application\UseCase\ProcessRateImport;

use App\Import\Application\Configuration\RateImportSettings;
use App\Import\Application\Contract\ImportJobRepositoryInterface;
use App\Import\Application\Message\ProcessRateImportMessage;
use App\Import\Domain\Enum\RateImportAction;
use App\Shared\Contracts\Import\StaleImportExecution;
use App\Shared\Contracts\Rates\RateImportPermanentFailure;
use App\Shared\Contracts\Rates\RateSetImportGateway;
use App\Support\Logging\Application\IncidentId;
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
        private ImportJobRepositoryInterface $repository,
        private RateSetImportGateway $provider,
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
        if (!$this->provider->isConfirmed($claim->requestedDate)) {
            $failure = null;
            try {
                $this->provider->ensureAvailable($claim->requestedDate, $claim->version);
                ++$attempts;
            } catch (StaleImportExecution) {
                return;
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
                $code = IncidentId::create();
                $action = $decision->allowed ? RateImportAction::Defer : RateImportAction::Fail;
                $saved = $this->repository->finish($claim, $action, $attempts, $decision->retryAt, $code, $failure->getMessage());
                $this->logger->log($decision->allowed ? 'notice' : 'error', 'Обработка задания импорта завершилась ошибкой.', ['requested_date' => $claim->requestedDate, 'version' => $claim->version, 'action' => $action->name, 'saved' => $saved, 'error_code' => $code, 'exception_class' => $failure::class]);

                return;
            }
            if (!$this->provider->isConfirmed($claim->requestedDate)) {
                throw new \RuntimeException('Источник не подтвердил календарную дату в PostgreSQL.');
            }
        }
        $saved = $this->repository->finish($claim, RateImportAction::Complete, $attempts);
        $this->logger->info('Завершение задания импорта.', ['requested_date' => $claim->requestedDate, 'version' => $claim->version, 'saved' => $saved]);
    }
}
