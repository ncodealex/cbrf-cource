<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Persistence;

use App\Cources\Application\Contract\RateImportJobRepositoryInterface;
use App\Cources\Application\Import\RateImportAction;
use App\Cources\Application\Import\RateImportClaim;
use App\Cources\Application\Import\RateImportError;
use App\Cources\Application\Import\RateImportErrorList;
use App\Cources\Application\Import\RateImportIncident;
use App\Cources\Application\Import\RateImportProgress;
use App\Cources\Application\Import\RateImportStatistics;
use App\Cources\Application\Import\RateImportStatus;
use App\Cources\Application\Import\RateImportTransitionPolicy;
use App\Cources\Application\Import\RatesImportPeriod;
use App\Cources\Application\Message\ProcessRateImportMessage;
use App\Shared\Domain\DateFormatInterface;
use App\Shared\Domain\DatePointParser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(RateImportJobRepositoryInterface::class)]
final readonly class DoctrineRateImportJobRepository implements RateImportJobRepositoryInterface
{
    public function __construct(
        private Connection $connection,
        private RateImportTransitionPolicy $policy = new RateImportTransitionPolicy(),
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function confirmedDates(RatesImportPeriod $period): array
    {
        $data = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT requested_date
                FROM rate_request_dates
                WHERE requested_date BETWEEN :from AND :to
                SQL,
            $this->bounds($period),
        );

        return $this->parseDates($data);
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
            'to' => $period->to->format(DateFormatInterface::ISO),
        ];
    }

    /**
     * @param list<mixed> $dates
     *
     * @return list<DatePoint>
     */
    private function parseDates(array $dates): array
    {
        return array_map(static function (mixed $date): DatePoint {
            if (!\is_string($date)) {
                throw new \UnexpectedValueException('PostgreSQL вернул некорректную календарную дату.');
            }

            return DatePointParser::parse($date);
        }, $dates);
    }

    /**
     * {@inheritdoc}
     */
    public function register(array $dates): array
    {
        if ([] === $dates) {
            return [];
        }

        $values = [];
        $parameters = ['status' => RateImportStatus::Waiting->value];
        foreach ($dates as $index => $date) {
            $name = 'date'.$index;
            $values[] = '(:'.$name.', :status)';
            $parameters[$name] = $date->format(DateFormatInterface::ISO);
        }

        $sql = str_replace(
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
     * {@inheritdoc}
     */
    public function statistics(RatesImportPeriod $period): RateImportStatistics
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                WITH confirmed AS (
                    SELECT requested_date
                    FROM rate_request_dates
                    WHERE requested_date BETWEEN :from AND :to
                ),
                states AS (
                    SELECT :done AS status
                    FROM confirmed

                    UNION ALL

                    SELECT j.status
                    FROM rate_import_jobs j
                    WHERE j.requested_date BETWEEN :from AND :to
                        AND NOT EXISTS (
                            SELECT 1
                            FROM confirmed c
                            WHERE c.requested_date = j.requested_date
                        )
                )
                SELECT status, COUNT(*) AS total
                FROM states
                GROUP BY status
                SQL,
            [...$this->bounds($period), 'done' => RateImportStatus::Done->value],
        );
        $statistics = ['waiting' => 0, 'processing' => 0, 'deferred' => 0, 'done' => 0, 'error' => 0];
        foreach ($rows as $row) {
            $status = $row['status'];
            $total = $row['total'];
            if (!\is_string($status) || !\array_key_exists($status, $statistics)
                || (!\is_int($total) && !\is_string($total))) {
                throw new \UnexpectedValueException('PostgreSQL вернул некорректную статистику заданий импорта.');
            }
            $statistics[$status] = (int) $total;
        }

        return new RateImportStatistics(
            waiting: $statistics[RateImportStatus::Waiting->value],
            processing: $statistics[RateImportStatus::Processing->value],
            deferred: $statistics[RateImportStatus::Deferred->value],
            done: $statistics[RateImportStatus::Done->value],
            error: $statistics[RateImportStatus::Error->value],
        );
    }

    /**
     * {@inheritdoc}
     */
    public function requeueUnconfirmedDone(RatesImportPeriod $period): int
    {
        $this->policy->assertAllowed(RateImportStatus::Done, RateImportAction::RepairDone);
        $sql = str_replace(
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
                'done' => RateImportStatus::Done->value,
            ],
        );

        return (int) $updated;
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
                'states' => $this->sources(RateImportAction::Refresh),
                'dates' => array_map(
                    static fn (DatePoint $date): string => $date->format(DateFormatInterface::ISO),
                    $dates
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
            static fn (RateImportStatus $status): string => $status->value,
            $this->policy->sources($action),
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
                    static fn (DatePoint $date): string => $date->format(DateFormatInterface::ISO),
                    $dates,
                ),
            ],
            ['dates' => ArrayParameterType::STRING],
        );
    }

    /**
     * {@inheritdoc}
     */
    public function reserveDispatch(int $limit, int $redispatchAfter, int $maxUnclaimed): array
    {
        return $this->connection->transactional(function () use ($limit, $redispatchAfter, $maxUnclaimed): array {
            /*
             * Общая короткая блокировка только диспетчеров гарантирует соблюдение
             * глобального лимита при конкурентном резервировании.
             */
            $this->connection->executeQuery(
                <<<'SQL'
                    SELECT pg_advisory_xact_lock(72834102)
                    SQL,
            )->free();
            $unclaimedValue = $this->connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM rate_import_jobs
                    WHERE status IN (:states)
                        AND dispatched_at > NOW() - (:delay * INTERVAL '1 second')
                    SQL,
                [
                    'states' => $this->sources(RateImportAction::Claim),
                    'delay' => $redispatchAfter,
                ],
                ['states' => ArrayParameterType::STRING],
            );
            $unclaimed = (int) $this->text($unclaimedValue);
            $count = min($limit, max(0, $maxUnclaimed - $unclaimed));
            if (0 === $count) {
                return [];
            }

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
                    'delay' => $redispatchAfter,
                    'limit' => $count,
                ],
                ['states' => ArrayParameterType::STRING, 'limit' => ParameterType::INTEGER],
            );

            return array_map(
                fn (array $row): ProcessRateImportMessage => new ProcessRateImportMessage(
                    $this->text($row['requested_date']),
                    (int) $this->text($row['version']),
                ),
                $rows,
            );
        });
    }

    private function text(mixed $value): string
    {
        if (!\is_string($value) && !\is_int($value)) {
            throw new \UnexpectedValueException('Некорректный ответ PostgreSQL.');
        }

        return (string) $value;
    }

    /**
     * {@inheritdoc}
     */
    public function claim(string $date, int $version, int $leaseSeconds): ?RateImportClaim
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                UPDATE rate_import_jobs
                SET status = :processing,
                    version = version + 1,
                    lease_until = NOW() + (:lease * INTERVAL '1 second'),
                    updated_at = NOW()
                WHERE requested_date = :date AND version = :version
                    AND status IN (:states)
                    AND available_at <= NOW()
                RETURNING requested_date, version, attempts
                SQL,
            [
                'date' => $date,
                'version' => $version,
                'lease' => $leaseSeconds,
                'processing' => RateImportStatus::Processing->value,
                'states' => $this->sources(RateImportAction::Claim),
            ],
            ['states' => ArrayParameterType::STRING],
        );

        return false === $row ? null : $this->toClaim($row);
    }

    /**
     * Преобразовать строку результата DBAL в снимок владения заданием.
     *
     * @param array<string, mixed> $row строка с датой, версией и числом попыток
     */
    private function toClaim(array $row): RateImportClaim
    {
        return new RateImportClaim(
            $this->text($row['requested_date']),
            (int) $this->text($row['version']),
            (int) $this->text($row['attempts']),
        );
    }

    /**
     * {@inheritdoc}
     */
    public function isConfirmed(string $date): bool
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
    public function finish(
        RateImportClaim $claim,
        RateImportAction $action,
        int $attempts,
        ?DatePoint $availableAt = null,
        ?string $errorCode = null,
        ?string $errorText = null,
    ): bool {
        $this->policy->assertAllowed(RateImportStatus::Processing, $action);
        $target = match ($action) {
            RateImportAction::Complete => RateImportStatus::Done,
            RateImportAction::Defer => RateImportStatus::Deferred,
            RateImportAction::Fail => RateImportStatus::Error,
            default => throw new \InvalidArgumentException('Недопустимое действие завершения задания.'),
        };

        return 1 === $this->connection->executeStatement(
            <<<'SQL'
                UPDATE rate_import_jobs
                SET status = :target,
                    version = version + 1,
                    attempts = :attempts,
                    lease_until = NULL,
                    dispatched_at = NULL,
                    available_at = COALESCE(CAST(:available AS timestamptz), NOW()),
                    error_code = :code,
                    error_text = :error,
                    completed_at = CASE WHEN :target = 'done' THEN NOW() ELSE NULL END,
                    updated_at = NOW()
                WHERE requested_date = :date AND status = :expected AND version = :version
                    AND (
                        :target <> 'done'
                        OR EXISTS (
                            SELECT 1
                            FROM rate_request_dates d
                            WHERE d.requested_date = :date
                        )
                    )
                SQL,
            [
                'date' => $claim->requestedDate,
                'version' => $claim->version,
                'expected' => RateImportStatus::Processing->value,
                'target' => $target->value,
                'attempts' => $attempts,
                'available' => $availableAt?->format('Y-m-d H:i:s.uP'),
                'code' => $errorCode,
                'error' => $errorText,
            ],
        );
    }

    /**
     * {@inheritdoc}
     */
    public function expired(int $limit): array
    {
        /*
         * Чтение не передаёт владение заданиями: recover повторно проверяет
         * lease, status и version в одной атомарной операции.
         */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT requested_date, version, attempts
                FROM rate_import_jobs
                WHERE status IN (:states) AND lease_until <= NOW()
                ORDER BY lease_until, requested_date
                LIMIT :limit
                SQL,
            ['states' => $this->sources(RateImportAction::Recover), 'limit' => $limit],
            ['states' => ArrayParameterType::STRING, 'limit' => ParameterType::INTEGER],
        );

        return array_map($this->toClaim(...), $rows);
    }

    /**
     * {@inheritdoc}
     */
    public function recover(RateImportClaim $claim, int $attempts, ?DatePoint $retryAt): bool
    {
        $this->policy->assertAllowed(RateImportStatus::Processing, RateImportAction::Recover);

        return 1 === $this->connection->executeStatement(
            <<<'SQL'
                WITH expired AS (
                    SELECT requested_date
                    FROM rate_import_jobs
                    WHERE requested_date = :date
                        AND status = :expected
                        AND version = :version
                        AND lease_until <= NOW()
                    FOR UPDATE SKIP LOCKED
                )
                UPDATE rate_import_jobs j
                SET status = :target,
                    version = j.version + 1,
                    attempts = :attempts,
                    available_at = COALESCE(CAST(:available AS timestamptz), NOW()),
                    lease_until = NULL,
                    dispatched_at = NULL,
                    error_text = :error,
                    error_code = :code,
                    completed_at = NULL,
                    updated_at = NOW()
                FROM expired e
                WHERE j.requested_date = e.requested_date
                SQL,
            [
                'date' => $claim->requestedDate,
                'version' => $claim->version,
                'expected' => RateImportStatus::Processing->value,
                'target' => null === $retryAt ? RateImportStatus::Error->value : RateImportStatus::Deferred->value,
                'attempts' => $attempts,
                'available' => $retryAt?->format('Y-m-d H:i:s.uP'),
                'error' => 'Истёк срок владения заданием.',
                'code' => RateImportIncident::create(),
            ],
        );
    }

    /**
     * {@inheritdoc}
     */
    public function errors(?string $date, int $limit): RateImportErrorList
    {
        $parameters = [
            'status' => RateImportStatus::Error->value,
            'date' => $date,
        ];
        $totalValue = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM rate_import_jobs
                WHERE status = :status
                    AND (CAST(:date AS date) IS NULL OR requested_date = :date)
                SQL,
            $parameters,
        );
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT requested_date, attempts, error_code, error_text
                FROM rate_import_jobs
                WHERE status = :status
                    AND (CAST(:date AS date) IS NULL OR requested_date = :date)
                ORDER BY updated_at DESC, requested_date DESC
                LIMIT :limit
                SQL,
            [...$parameters, 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return new RateImportErrorList(
            total: (int) $this->text($totalValue),
            items: array_map(
                fn (array $row): RateImportError => new RateImportError(
                    requestedDate: $this->text($row['requested_date']),
                    attempts: (int) $this->text($row['attempts']),
                    errorCode: null === $row['error_code'] ? '' : $this->text($row['error_code']),
                    errorText: null === $row['error_text'] ? '' : $this->text($row['error_text']),
                ),
                $rows,
            ),
        );
    }

    /**
     * {@inheritdoc}
     */
    public function retryErrors(?string $date): int
    {
        $action = RateImportAction::RetryError;
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
                throw new \InvalidArgumentException('Задание на указанную дату не найдено.');
            }

            $this->policy->assertAllowed(RateImportStatus::from($this->text($row['status'])), $action);
            $parameters['date'] = $date;
            $parameters['version'] = $this->text($row['version']);
        }

        $where = null === $date
            ? <<<'SQL'
                j.status IN (:states)
                SQL
            : <<<'SQL'
                j.status IN (:states)
                    AND j.requested_date = :date
                    AND j.version = :version
                SQL;
        $sql = str_replace(
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

        return (int) $updated;
    }

    /**
     * {@inheritdoc}
     */
    public function progress(RatesImportPeriod $period): RateImportProgress
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    COUNT(d.requested_date) AS confirmed,
                    COUNT(*) FILTER (
                        WHERE d.requested_date IS NULL AND j.status = 'waiting'
                    ) AS waiting,
                    COUNT(*) FILTER (
                        WHERE d.requested_date IS NULL AND j.status = 'processing'
                    ) AS processing,
                    COUNT(*) FILTER (
                        WHERE d.requested_date IS NULL AND j.status = 'deferred'
                    ) AS deferred,
                    COUNT(*) FILTER (
                        WHERE d.requested_date IS NULL AND j.status = 'error'
                    ) AS error,
                    COUNT(*) FILTER (
                        WHERE d.requested_date IS NULL AND j.status = 'done'
                    ) AS inconsistent,
                    COUNT(*) FILTER (
                        WHERE d.requested_date IS NULL AND j.requested_date IS NULL
                    ) AS missing
                FROM generate_series(
                    CAST(:from AS date),
                    CAST(:to AS date),
                    INTERVAL '1 day'
                ) dates(day)
                LEFT JOIN rate_request_dates d ON d.requested_date = dates.day::date
                LEFT JOIN rate_import_jobs j ON j.requested_date = dates.day::date
                SQL,
            $this->bounds($period),
        );
        if (false === $row) {
            throw new \UnexpectedValueException('Не получена статистика импорта.');
        }

        return new RateImportProgress(
            confirmed: (int) $this->text($row['confirmed']),
            waiting: (int) $this->text($row['waiting']),
            processing: (int) $this->text($row['processing']),
            deferred: (int) $this->text($row['deferred']),
            error: (int) $this->text($row['error']),
            inconsistentDone: (int) $this->text($row['inconsistent']),
            missing: (int) $this->text($row['missing']),
        );
    }
}
