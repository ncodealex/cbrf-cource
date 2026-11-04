<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Persistence;

use App\Import\Application\Contract\ImportJobRepositoryInterface;
use App\Import\Application\Message\ProcessRateImportMessage;
use App\Import\Domain\Entity\ImportJob;
use App\Import\Domain\Enum\RateImportAction;
use App\Import\Domain\Enum\RateImportStatus;
use App\Import\Domain\ValueObject\ImportJobExecution;
use App\Import\Domain\ValueObject\RatesImportPeriod;
use App\Shared\Contracts\Import\ImportExecutionFence;
use App\Shared\Contracts\Import\ImportJobResetter;
use App\Shared\Contracts\Import\StaleImportExecution;
use App\Shared\Domain\DateFormatInterface;
use App\Shared\Domain\DatePointParser;
use App\Support\Logging\Application\IncidentId;
use Closure;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use UnexpectedValueException;
use function is_int;
use function is_string;

#[AsAlias(ImportJobRepositoryInterface::class)]
#[AsAlias(ImportJobResetter::class)]
#[AsAlias(ImportExecutionFence::class)]
final readonly class DoctrineImportJobRepository implements ImportJobRepositoryInterface, ImportJobResetter, ImportExecutionFence
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function register(array $dates): array
    {
        if ([] === $dates) {
            return [];
        }

        $values     = [];
        $parameters = ['status' => RateImportStatus::Waiting->value];
        foreach ($dates as $index => $date) {
            $name              = 'date' . $index;
            $values[]          = '(:' . $name . ', :status)';
            $parameters[$name] = $date->format(DateFormatInterface::ISO);
        }
        /// ON CONFLICT (requested_date) DO NOTHING пропустить строки которые есть
        $sql  = str_replace(
            '{{ values }}',
            implode(', ', $values),
            <<<'SQL'
                INSERT INTO rate_import_jobs (requested_date, status)
                VALUES {{ values }}
                ON CONFLICT (requested_date) DO NOTHING
                RETURNING requested_date
                SQL,
        );
        $data = $this->connection->fetchFirstColumn(
            $sql,
            $parameters,
        );

        return $this->parseDates($data);
    }

    /**
     * @param list<mixed> $dates
     *
     * @return list<DatePoint>
     */
    private function parseDates(array $dates): array
    {
        return array_map(static function (mixed $date): DatePoint {
            if (!is_string($date)) {
                throw new UnexpectedValueException('PostgreSQL вернул некорректную календарную дату.');
            }

            return DatePointParser::parse($date);
        }, $dates);
    }

    /**
     * {@inheritdoc}
     */
    public function requeueUnconfirmedDone(RatesImportPeriod $period): int
    {
        RateImportStatus::Done->assertAllows(RateImportAction::RepairDone);
        $sql     = str_replace(
            '{{ assignments }}',
            $this->resetAssignments(),
            <<<'SQL'
                UPDATE rate_import_jobs AS j
                SET {{ assignments }}
                WHERE j.requested_date BETWEEN :from AND :to
                    AND j.status = :done
                    AND NOT EXISTS (
                        SELECT 1
                        FROM rate_request_dates d
                        WHERE d.requested_date = j.requested_date
                    )
                SQL,
        );
        $updated = $this->connection->executeStatement(
            $sql,
            [
                ...$this->bounds($period),
                'waiting' => RateImportStatus::Waiting->value,
                'done'    => RateImportStatus::Done->value,
            ],
        );

        return (int)$updated;
    }

    /**
     * Сформировать присваивания для сброса задания.
     *
     * Версия растёт при каждом сбросе существующей строки и ограждает её
     * от устаревших исполнителей.
     */
    private function resetAssignments(): string
    {
        return <<<'SQL'
            status = :waiting,
            version = j.version + 1,
            attempts = 0,
            available_at = NOW(),
            lease_until = NULL,
            dispatched_at = NULL,
            error_text = NULL,
            error_code = NULL,
            completed_at = NULL,
            updated_at = NOW()
            SQL;
    }

    /**
     * Подготовить границы периода для параметров SQL-запроса.
     *
     * @return array{from: string, to: string} даты в формате внутреннего хранения
     */
    private function bounds(RatesImportPeriod $period): array
    {
        return [
            'from' => $period->from->format(DateFormatInterface::ISO),
            'to'   => $period->to->format(DateFormatInterface::ISO),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function resetForRefresh(DatePoint ...$dates): void
    {
        if ([] === $dates) {
            return;
        }

        $sql = str_replace(
            '{{ assignments }}',
            $this->resetAssignments(),
            <<<'SQL'
                UPDATE rate_import_jobs AS j
                SET {{ assignments }}
                WHERE j.requested_date IN (:dates)
                    AND j.status IN (:states)
                SQL,
        );
        $this->connection->executeStatement(
            $sql,
            [
                'waiting' => RateImportStatus::Waiting->value,
                'states'  => $this->sources(RateImportAction::Refresh),
                'dates'   => array_map(
                    static fn(DatePoint $date): string => $date->format(DateFormatInterface::ISO),
                    $dates,
                ),
            ],
            ['dates' => ArrayParameterType::STRING, 'states' => ArrayParameterType::STRING],
        );
    }

    /**
     * Получить строковые значения исходных статусов для перехода.
     *
     * @return list<string> значения статусов для SQL-параметра
     */
    private function sources(RateImportAction $action): array
    {
        return array_map(
            static fn(RateImportStatus $status): string => $status->value,
            RateImportStatus::allowedFor($action),
        );
    }

    /**
     * {@inheritdoc}
     */
    public function delete(DatePoint ...$dates): void
    {
        if ([] === $dates) {
            return;
        }

        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM rate_import_jobs
                WHERE requested_date IN (:dates)
                SQL,
            [
                'dates' => array_map(
                    static fn(DatePoint $date): string => $date->format(DateFormatInterface::ISO),
                    $dates,
                ),
            ],
            ['dates' => ArrayParameterType::STRING],
        );
    }

    /**
     * {@inheritdoc}
     */
    public function reserveDispatch(
        int $limit,
        int $redispatchAfter,
        int $maxUnclaimed,
    ): array {
        return $this->connection->transactional(function () use ($limit, $redispatchAfter, $maxUnclaimed): array {
            /**
             * Общая блокировка +  освобождение ресурсов Result
             */
            $dispatchLock = $this->connection->executeQuery(
                <<<'SQL'
                    SELECT pg_advisory_xact_lock(72834102)
                    SQL,
            );
            $dispatchLock->free();
            $unclaimedValue = $this->connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM rate_import_jobs
                    WHERE status IN (:states)
                        AND dispatched_at > NOW() - (:delay * INTERVAL '1 second')
                    SQL,
                [
                    'states' => $this->sources(RateImportAction::Claim),
                    'delay'  => $redispatchAfter,
                ],
                ['states' => ArrayParameterType::STRING],
            );
            $unclaimed      = (int)$unclaimedValue;
            $count          = min($limit, max(0, $maxUnclaimed - $unclaimed));

            if (0 === $count) {
                return [];
            }

            /**
             * 0. pg_advisory_xact_lock
             * 1. В CTE забираем то, что подходит по статусу и таймингам и не заблочено по FOR UPDATE SKIP LOCKED
             * 2. Обновляем время дистпатча и обновления
             */
            $rows = $this->connection->fetchAllAssociative(
                <<<'SQL'
                    WITH ready AS (
                        SELECT requested_date
                        FROM rate_import_jobs
                        WHERE status IN (:states)
                            AND available_at <= NOW()
                            AND (
                                dispatched_at IS NULL
                                OR dispatched_at <= NOW() - (:delay * INTERVAL '1 second')
                            )
                        ORDER BY available_at, requested_date
                        LIMIT :limit
                        FOR UPDATE SKIP LOCKED
                    )
                    UPDATE rate_import_jobs j
                    SET dispatched_at = NOW(), updated_at = NOW()
                    FROM ready r
                    WHERE j.requested_date = r.requested_date
                    RETURNING j.requested_date, j.version
                    SQL,
                [
                    'states' => $this->sources(RateImportAction::Claim),
                    'delay'  => $redispatchAfter,
                    'limit'  => $count,
                ],
                [
                    'states' => ArrayParameterType::STRING,
                    'limit'  => ParameterType::INTEGER,
                ],
            );

            return array_map(
                fn(array $row): ProcessRateImportMessage => new ProcessRateImportMessage(
                    $this->text($row['requested_date']),
                    (int)$this->text($row['version']),
                ),
                $rows,
            );
        });
    }

    private function text(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new UnexpectedValueException('Некорректный ответ PostgreSQL.');
        }

        return (string)$value;
    }

    /**
     * {@inheritdoc}
     */
    public function write(
        DatePoint $date,
        int $version,
        Closure $write,
    ): void {
        $this->connection->transactional(function () use ($date, $version, $write): void {
            $row = $this->connection->fetchOne(
                <<<'SQL'
                    SELECT version
                    FROM rate_import_jobs
                    WHERE requested_date = :date AND version = :version AND status = :status
                    FOR UPDATE
                    SQL,
                [
                    'date'    => $date->format(DateFormatInterface::ISO),
                    'version' => $version,
                    'status'  => RateImportStatus::Processing->value,
                ],
            );
            if (false === $row) {
                throw new StaleImportExecution('Исполнение задания устарело.');
            }
            $write();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function claim(
        string $date,
        int $version,
        int $leaseSeconds,
    ): ?ImportJobExecution {
        return $this->connection->transactional(function () use ($date, $version, $leaseSeconds): ?ImportJobExecution {
            $job = $this->locked($date, $version);
            if (null === $job || !$job->status->allows(RateImportAction::Claim)) {
                return null;
            }
            $now = $this->now();
            if ($job->availableAt > $now) {
                return null;
            }
            $execution = $job->claim($now, $now->modify('+' . $leaseSeconds . ' seconds'));
            $this->save($job, $version);

            return $execution;
        });
    }

    private function locked(
        string $date,
        int $version,
        bool $skipLocked = false,
    ): ?ImportJob {
        $sql = <<<'SQL'
            SELECT *
            FROM rate_import_jobs
            WHERE requested_date = :date AND version = :version
            FOR UPDATE
            SQL;
        if ($skipLocked) {
            $sql .= ' SKIP LOCKED';
        }
        $row = $this->connection->fetchAssociative($sql, ['date' => $date, 'version' => $version]);

        return false === $row ? null : ImportJobHydrator::fromRow($row);
    }

    private function now(): DatePoint
    {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT clock_timestamp()::text
                SQL,
        );

        return new DatePoint($this->text($value));
    }

    private function save(
        ImportJob $job,
        int $expectedVersion,
    ): bool {
        $affectedRows = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE rate_import_jobs
                SET status = :status, 
                    version = :version, 
                    attempts = :attempts,
                    available_at = :available, 
                    lease_until = :lease, 
                    dispatched_at = :dispatched,
                    completed_at = :completed, 
                    error_code = :code, 
                    error_text = :error, 
                    updated_at = NOW()
                WHERE requested_date = :date AND version = :expected
                SQL,
            [
                'date'       => $job->requestedDate->format(DateFormatInterface::ISO),
                'expected'   => $expectedVersion,
                'version'    => $job->version,
                'status'     => $job->status->value,
                'attempts'   => $job->attempts,
                'available'  => $job->availableAt?->format('Y-m-d H:i:s.uP'),
                'lease'      => $job->leaseUntil?->format('Y-m-d H:i:s.uP'),
                'dispatched' => $job->dispatchedAt?->format('Y-m-d H:i:s.uP'),
                'completed'  => $job->completedAt?->format('Y-m-d H:i:s.uP'),
                'code'       => $job->errorCode,
                'error'      => $job->errorText,
            ],
        );

        return 1 === $affectedRows;
    }

    /**
     * {@inheritdoc}
     */
    public function finish(
        ImportJobExecution $claim,
        RateImportAction $action,
        int $attempts,
        ?DatePoint $availableAt = null,
        ?string $errorCode = null,
        ?string $errorText = null,
    ): bool {
        RateImportStatus::Processing->assertAllows($action);

        return $this->connection->transactional(function () use (
            $claim,
            $action,
            $attempts,
            $availableAt,
            $errorCode,
            $errorText
        ): bool {
            $date = $claim->requestedDate->format(DateFormatInterface::ISO);
            $job  = $this->locked($date, $claim->version);
            if (null === $job || RateImportStatus::Processing !== $job->status) {
                return false;
            }
            $now = $this->now();
            if (RateImportAction::Complete === $action && !$this->isConfirmed($date)) {
                return false;
            }
            match ($action) {
                RateImportAction::Complete => $job->complete($now, true, $attempts),
                RateImportAction::Defer => $job->defer($availableAt ?? $now, $attempts, $errorCode, $errorText),
                RateImportAction::Fail => $job->fail($now, $attempts, $errorCode, $errorText),
                default => throw new InvalidArgumentException('Недопустимое действие завершения задания.'),
            };

            return $this->save($job, $claim->version);
        });
    }

    /**
     * Проверить календарную связь внутри транзакции завершения задания.
     */
    private function isConfirmed(string $date): bool
    {
        $result = $this->connection->fetchOne(
            <<<'SQL'
                SELECT 1
                FROM rate_request_dates
                WHERE requested_date = :date
                SQL,
            ['date' => $date],
        );

        return false !== $result;
    }

    /**
     * {@inheritdoc}
     */
    public function recover(
        ImportJob $claim,
        int $attempts,
        ?DatePoint $retryAt,
    ): bool {
        return $this->connection->transactional(function () use ($claim, $attempts, $retryAt): bool {
            // 1. Блокируем с проверкой того, что не заблокировано
            /// FOR UPDATE SKIP LOCKED
            $job = $this->locked(
                $claim->requestedDate->format(DateFormatInterface::ISO),
                $claim->version,
                true
            );

            $now = $this->now();

            if (null === $job
                || RateImportStatus::Processing !== $job->status
                || $job->leaseUntil > $now
            ) {
                return false;
            }

            $job->recover($now, $attempts, $retryAt, IncidentId::create());

            return $this->save($job, $claim->version);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function retryErrors(?string $date): int
    {
        $action     = RateImportAction::RetryError;
        $parameters = ['waiting' => RateImportStatus::Waiting->value, 'states' => $this->sources($action)];
        if (null !== $date) {
            $row = $this->connection->fetchAssociative(
                <<<'SQL'
                    SELECT status, version
                    FROM rate_import_jobs
                    WHERE requested_date = :date
                    SQL,
                ['date' => $date],
            );
            if (false === $row) {
                throw new InvalidArgumentException('Задание на указанную дату не найдено.');
            }

            RateImportStatus::from($this->text($row['status']))->assertAllows($action);
            $parameters['date']    = $date;
            $parameters['version'] = $this->text($row['version']);
        }

        $where   = null === $date
            ? <<<'SQL'
                j.status IN (:states)
                SQL
            : <<<'SQL'
                j.status IN (:states)
                    AND j.requested_date = :date
                    AND j.version = :version
                SQL;
        $sql     = str_replace(
            ['{{ assignments }}', '{{ conditions }}'],
            [$this->resetAssignments(), $where],
            <<<'SQL'
                UPDATE rate_import_jobs j
                SET {{ assignments }}
                WHERE {{ conditions }}
                SQL,
        );
        $updated = $this->connection->executeStatement(
            $sql,
            $parameters,
            ['states' => ArrayParameterType::STRING],
        );

        return (int)$updated;
    }
}
