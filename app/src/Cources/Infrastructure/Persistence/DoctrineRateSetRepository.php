<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Persistence;

use App\Cources\Application\Contract\RateSetRepositoryInterface;
use App\Cources\Application\Exception\RateSetConflict;
use App\Cources\Application\Exception\RateSetNotFound;
use App\Cources\Domain\Entity\RateSet;
use App\Cources\Infrastructure\Mapping\RateSetMapper;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(RateSetRepositoryInterface::class)]
final readonly class DoctrineRateSetRepository implements RateSetRepositoryInterface
{
    public function __construct(private Connection $connection, private RateSetMapper $mapper)
    {
    }

    public function get(DatePoint $requestDate): RateSet
    {
        $row = $this->connection->fetchAssociative(
            <<<SQL
                    SELECT
                        s.effective_date,
                        s.rates
                    FROM rate_request_dates d
                    JOIN rate_sets s ON s.effective_date = d.effective_date
                    WHERE d.requested_date = :date
                    SQL,
            ['date' => $requestDate->format('Y-m-d')],
        );
        if (false === $row) {
            throw new RateSetNotFound('Набор курсов не найден на дату ' . $requestDate->format('Y-m-d'));
        }

        return $this->mapper->fromArray([
            'effectiveDate' => $row['effective_date'],
            'items'         => $this->decodeRates($row['rates']),
        ]);
    }

    private function decodeRates(mixed $rates): mixed
    {
        if (!\is_string($rates)) {
            throw new \UnexpectedValueException('В PostgreSQL отсутствует корректное JSON-представление котировок');
        }

        return json_decode($rates, true, 512, \JSON_THROW_ON_ERROR);
    }

    public function save(DatePoint $requestDate, RateSet $rateSet): void
    {
        $requestedDate = $requestDate->format('Y-m-d');
        $effectiveDate = $rateSet->date();
        $rates         = json_encode($this->mapper->toArray($rateSet)['items'],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        $this->connection->transactional(function () use ($requestedDate, $effectiveDate, $rates, $rateSet): void {

            $inserted = $this->connection->fetchOne(
                <<<SQL
                        INSERT INTO rate_sets (effective_date, rates) 
                        VALUES (:date, CAST(:rates AS jsonb)) 
                        ON CONFLICT (effective_date) DO NOTHING RETURNING effective_date 
                        SQL,
                [
                    'date'  => $effectiveDate,
                    'rates' => $rates,
                ],
            );
            /**
             *  При наличии ON CONFLICT DO NOTHING соответствующего условия вставка строки
             *    может быть невозможна из-за результатов другой транзакции,
             *    последствия которой не видны в INSERT снимке состояния.
             *
             * @see https://www.postgresql.org/docs/18/transaction-iso.html?#XACT-READ-COMMITTED
             */
            if (false === $inserted) {
                $existingRates = $this->connection->fetchOne(
                    <<<SQL
                            SELECT rates 
                            FROM rate_sets 
                            WHERE effective_date = :date
                            SQL,
                    [
                        'date' => $effectiveDate,
                    ]
                );

                $existing = $this->mapper->fromArray([
                    'effectiveDate' => $effectiveDate,
                    'items'         => $this->decodeRates($existingRates),
                ]);
                /// Если хэши не совпадают - исключение
                if ($existing->contentHash() !== $rateSet->contentHash()) {
                    throw new RateSetConflict('Сохранённый набор курсов отличается от полученного на дату ' . $effectiveDate);
                }
            }
            // Соединяем rate_request_dates с effective_date в rates
            $inserted = $this->connection->fetchOne(
                <<<SQL
                        INSERT INTO rate_request_dates (requested_date, effective_date) 
                        VALUES (:requested, :effective) 
                        ON CONFLICT (requested_date) DO NOTHING RETURNING requested_date 
                        SQL,
                [
                    'requested' => $requestedDate,
                    'effective' => $effectiveDate,
                ],
            );
            if (false === $inserted) {
                $existingDate = $this->connection->fetchOne(
                    <<<SQL
                        SELECT effective_date 
                            FROM rate_request_dates 
                        WHERE requested_date = :date
                        SQL,
                    [
                        'date' => $requestedDate,
                    ]);
                if ($existingDate !== $effectiveDate) {
                    throw new RateSetConflict('Для календарной даты ' . $requestedDate . ' уже подтверждена другая дата действия курсов');
                }
            }
        });
    }
}
