<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Persistence;

use App\Cources\Application\Contract\RateSetRepositoryInterface;
use App\Cources\Application\Exception\RateSetConflict;
use App\Cources\Application\Exception\RateSetNotFound;
use App\Cources\Domain\Entity\RateSet;
use App\Cources\Infrastructure\Mapping\RateSetMapper;
use App\Shared\Domain\DateFormatInterface;
use App\Shared\Domain\DatePointParser;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(RateSetRepositoryInterface::class)]
final readonly class DoctrineRateSetRepository implements RateSetRepositoryInterface
{
    public function __construct(
        private Connection $connection,
        private RateSetMapper $mapper,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function get(DatePoint $requestDate): RateSet
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT s.effective_date, s.rates
                FROM rate_request_dates d
                JOIN rate_sets s ON s.effective_date = d.effective_date
                WHERE d.requested_date = :date
                SQL,
            ['date' => $requestDate->format(DateFormatInterface::ISO)],
        );
        if (false === $row) {
            throw new RateSetNotFound('Набор курсов не найден на дату '.$requestDate->format(DateFormatInterface::ISO));
        }

        return $this->mapper->fromArray([
            'effectiveDate' => $row['effective_date'],
            'items' => $this->decodeRates($row['rates']),
        ]);
    }

    private function decodeRates(mixed $rates): mixed
    {
        if (!\is_string($rates)) {
            throw new \UnexpectedValueException('В PostgreSQL отсутствует корректное JSON-представление котировок');
        }

        return json_decode($rates, true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * {@inheritdoc}
     */
    public function save(
        DatePoint $requestDate,
        RateSet $rateSet,
    ): void {
        $requestedDate = $requestDate->format(DateFormatInterface::ISO);
        $effectiveDate = $rateSet->date();
        $rates = json_encode(
            $this->mapper->toArray($rateSet)['items'],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE,
        );

        $this->connection->transactional(function () use ($requestedDate, $effectiveDate, $rates, $rateSet): void {
            $inserted = $this->connection->fetchOne(
                <<<'SQL'
                    INSERT INTO rate_sets (effective_date, rates)
                    VALUES (:date, CAST(:rates AS jsonb))
                    ON CONFLICT (effective_date) DO NOTHING
                    RETURNING effective_date
                    SQL,
                [
                    'date' => $effectiveDate,
                    'rates' => $rates,
                ],
            );
            if (false === $inserted) {
                $existingRates = $this->connection->fetchOne(
                    <<<'SQL'
                        SELECT rates
                        FROM rate_sets
                        WHERE effective_date = :date
                        SQL,
                    ['date' => $effectiveDate],
                );

                $existing = $this->mapper->fromArray([
                    'effectiveDate' => $effectiveDate,
                    'items' => $this->decodeRates($existingRates),
                ]);
                if ($existing->contentHash() !== $rateSet->contentHash()) {
                    throw new RateSetConflict('Сохранённый набор курсов отличается от полученного на дату '.$effectiveDate);
                }
            }

            $inserted = $this->connection->fetchOne(
                <<<'SQL'
                    INSERT INTO rate_request_dates (requested_date, effective_date)
                    VALUES (:requested, :effective)
                    ON CONFLICT (requested_date) DO NOTHING
                    RETURNING requested_date
                    SQL,
                [
                    'requested' => $requestedDate,
                    'effective' => $effectiveDate,
                ],
            );
            if (false === $inserted) {
                $existingDate = $this->connection->fetchOne(
                    <<<'SQL'
                        SELECT effective_date
                        FROM rate_request_dates
                        WHERE requested_date = :date
                        SQL,
                    ['date' => $requestedDate],
                );
                if ($existingDate !== $effectiveDate) {
                    throw new RateSetConflict('Для календарной даты '.$requestedDate.' уже подтверждена другая дата действия курсов');
                }
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function relatedRequestDates(DatePoint $requestDate): array
    {
        $dates = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT related.requested_date
                FROM rate_request_dates target
                JOIN rate_request_dates related ON related.effective_date = target.effective_date
                WHERE target.requested_date = :date
                ORDER BY related.requested_date
                SQL,
            ['date' => $requestDate->format(DateFormatInterface::ISO)],
        );

        return array_map(
            static function (mixed $date): DatePoint {
                if (!\is_string($date)) {
                    throw new \UnexpectedValueException('PostgreSQL вернул некорректную календарную дату');
                }

                return DatePointParser::parse($date);
            },
            $dates,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function delete(DatePoint $requestDate): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM rate_sets s
                USING rate_request_dates target
                WHERE target.requested_date = :date
                    AND s.effective_date = target.effective_date
                SQL,
            ['date' => $requestDate->format(DateFormatInterface::ISO)],
        );
    }
}
