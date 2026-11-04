<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Persistence;

use App\Import\Domain\Entity\ImportJob;
use App\Import\Domain\Enum\RateImportStatus;
use App\Shared\Domain\DatePointParser;
use Symfony\Component\Clock\DatePoint;

final class ImportJobHydrator
{
    /**
     * @param array<string, mixed> $row строка DBAL со всеми полями задания
     */
    public static function fromRow(array $row): ImportJob
    {
        $text = static function (mixed $value): string {
            if (!\is_string($value) && !\is_int($value)) {
                throw new \UnexpectedValueException('Некорректное поле задания PostgreSQL.');
            }

            return (string) $value;
        };
        $time = static fn (mixed $value): ?DatePoint => null === $value ? null : new DatePoint($text($value));

        return new ImportJob(
            requestedDate: DatePointParser::parse($text($row['requested_date'])),
            status: RateImportStatus::from($text($row['status'])),
            version: (int) $text($row['version']),
            attempts: (int) $text($row['attempts']),
            availableAt: $time($row['available_at']),
            leaseUntil: $time($row['lease_until']),
            dispatchedAt: $time($row['dispatched_at']),
            completedAt: $time($row['completed_at']),
            errorCode: null === $row['error_code'] ? null : $text($row['error_code']),
            errorText: null === $row['error_text'] ? null : $text($row['error_text']),
        );
    }
}
