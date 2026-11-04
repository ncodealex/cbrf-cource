<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Gateway;

use App\Cources\Application\Contract\ExternalRateSetGatewayDatePolicyInterface;
use App\Cources\Application\Service\RateSetResolver;
use App\Shared\Contracts\Import\ImportExecutionFence;
use App\Shared\Contracts\Rates\RateSetImportGateway;
use App\Shared\Domain\DateFormatInterface;
use App\Shared\Domain\DatePointParser;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(RateSetImportGateway::class)]
final readonly class StoredRateSetImportGateway implements RateSetImportGateway
{
    public function __construct(
        private RateSetResolver $resolver,
        private Connection $connection,
        private ExternalRateSetGatewayDatePolicyInterface $datePolicy,
        private ImportExecutionFence $fence,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function ensureAvailable(
        DatePoint $requestedDate,
        ?int $executionVersion = null,
    ): void {
        $this->resolver->resolve($requestedDate, null === $executionVersion ? null : fn (\Closure $write) => $this->fence->write($requestedDate, $executionVersion, $write), true);
    }

    /**
     * {@inheritdoc}
     */
    public function isConfirmed(DatePoint $requestedDate): bool
    {
        $result = $this->connection->fetchOne(
            <<<'SQL'
                SELECT 1
                FROM rate_request_dates
                WHERE requested_date = :date
                SQL,
            ['date' => $requestedDate->format(DateFormatInterface::ISO)],
        );

        return false !== $result;
    }

    /**
     * {@inheritdoc}
     */
    public function confirmedDates(
        DatePoint $from,
        DatePoint $to,
    ): array {
        $rows = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT requested_date
                FROM rate_request_dates
                WHERE requested_date BETWEEN :from AND :to
                ORDER BY requested_date
                SQL,
            ['from' => $from->format(DateFormatInterface::ISO), 'to' => $to->format(DateFormatInterface::ISO)],
        );

        return array_map(static function (mixed $date): DatePoint {
            if (!\is_string($date)) {
                throw new \UnexpectedValueException('Некорректная дата PostgreSQL.');
            }

            return DatePointParser::parse($date);
        }, $rows);
    }

    /**
     * {@inheritdoc}
     */
    public function minRequestDate(): DatePoint
    {
        return $this->datePolicy->minRequestDate();
    }
}
