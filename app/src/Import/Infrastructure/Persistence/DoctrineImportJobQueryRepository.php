<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Persistence;

use App\Import\Application\Contract\ImportJobQueryRepositoryInterface;
use App\Import\Application\DTO\RateImportError;
use App\Import\Application\DTO\RateImportErrorList;
use App\Import\Application\DTO\RateImportProgress;
use App\Import\Application\DTO\RateImportStatistics;
use App\Import\Domain\Enum\RateImportAction;
use App\Import\Domain\Enum\RateImportStatus;
use App\Import\Domain\ValueObject\RatesImportPeriod;
use App\Shared\Domain\DateFormatInterface;
use App\Shared\Domain\DatePointParser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ImportJobQueryRepositoryInterface::class)]
final readonly class DoctrineImportJobQueryRepository implements ImportJobQueryRepositoryInterface
{
    public function __construct(
        private Connection $connection,
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
    public function expired(int $limit): array
    {
        /*
         * Чтение не передаёт владение заданиями: recover повторно проверяет
         * lease, status и version в одной атомарной операции.
         */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT *
                FROM rate_import_jobs
                WHERE status IN (:states) AND lease_until <= NOW()
                ORDER BY lease_until, requested_date
                LIMIT :limit
                SQL,
            ['states' => $this->sources(RateImportAction::Recover), 'limit' => $limit],
            ['states' => ArrayParameterType::STRING, 'limit' => ParameterType::INTEGER],
        );

        return array_map(ImportJobHydrator::fromRow(...), $rows);
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
            RateImportStatus::allowedFor($action),
        );
    }

    /**
     * {@inheritdoc}
     */
    public function errors(
        ?string $date,
        int $limit,
    ): RateImportErrorList {
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
