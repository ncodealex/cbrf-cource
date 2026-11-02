<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Persistence;

use App\Cources\Application\Contract\RateImportJobRepositoryInterface;
use App\Cources\Application\Import\RateImportStatus;
use App\Cources\Application\Import\RatesImportPeriod;
use App\Shared\Domain\DateFormatInterface;
use App\Shared\Domain\DatePointParser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(RateImportJobRepositoryInterface::class)]
final readonly class DoctrineRateImportJobRepository implements RateImportJobRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function confirmedDates(RatesImportPeriod $period): array
    {
        return $this->parseDates($this->connection->fetchFirstColumn(
            'SELECT requested_date FROM rate_request_dates WHERE requested_date BETWEEN :from AND :to',
            $this->bounds($period),
        ));
    }

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

        return $this->parseDates($this->connection->fetchFirstColumn(
            'INSERT INTO rate_import_jobs (requested_date, status) VALUES '.implode(', ', $values)
            .' ON CONFLICT (requested_date) DO NOTHING RETURNING requested_date',
            $parameters,
        ));
    }

    public function statistics(RatesImportPeriod $period): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                WITH confirmed AS (
                    SELECT requested_date FROM rate_request_dates
                    WHERE requested_date BETWEEN :from AND :to
                ), states AS (
                    SELECT :done AS status FROM confirmed
                    UNION ALL
                    SELECT j.status FROM rate_import_jobs j
                    WHERE j.requested_date BETWEEN :from AND :to
                      AND NOT EXISTS (SELECT 1 FROM confirmed c WHERE c.requested_date = j.requested_date)
                )
                SELECT status, COUNT(*) AS total FROM states GROUP BY status
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

        return $statistics;
    }

    public function requeueUnconfirmedDone(RatesImportPeriod $period): int
    {
        $updated = $this->connection->executeStatement(
            'UPDATE rate_import_jobs AS j SET '.$this->resetAssignments().'
             WHERE j.requested_date BETWEEN :from AND :to AND j.status = :done
               AND NOT EXISTS (SELECT 1 FROM rate_request_dates d WHERE d.requested_date = j.requested_date)',
            [...$this->bounds($period), 'waiting' => RateImportStatus::Waiting->value, 'done' => RateImportStatus::Done->value],
        );

        return (int) $updated;
    }

    public function resetForRefresh(DatePoint ...$dates): void
    {
        if ([] === $dates) {
            return;
        }
        $this->connection->executeStatement(
            'UPDATE rate_import_jobs AS j SET '.$this->resetAssignments().' WHERE j.requested_date IN (:dates)',
            [
                'waiting' => RateImportStatus::Waiting->value,
                'dates' => array_map(static fn (DatePoint $date): string => $date->format(DateFormatInterface::ISO), $dates),
            ],
            ['dates' => ArrayParameterType::STRING],
        );
    }

    /** Версия растёт при каждом сбросе существующей строки и ограждает её от устаревших исполнителей. */
    private function resetAssignments(): string
    {
        return <<<'SQL'
            status = :waiting, version = j.version + 1, attempts = 0,
            available_at = NOW(), lease_until = NULL, dispatched_at = NULL,
            error_text = NULL, error_code = NULL, completed_at = NULL, updated_at = NOW()
            SQL;
    }

    public function delete(DatePoint ...$dates): void
    {
        if ([] === $dates) {
            return;
        }
        $this->connection->executeStatement(
            'DELETE FROM rate_import_jobs WHERE requested_date IN (:dates)',
            ['dates' => array_map(static fn (DatePoint $date): string => $date->format(DateFormatInterface::ISO), $dates)],
            ['dates' => ArrayParameterType::STRING],
        );
    }

    /** @return array{from: string, to: string} */
    private function bounds(RatesImportPeriod $period): array
    {
        return ['from' => $period->from->format(DateFormatInterface::ISO), 'to' => $period->to->format(DateFormatInterface::ISO)];
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
}
