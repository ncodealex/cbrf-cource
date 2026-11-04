<?php

declare(strict_types=1);

use App\Import\Infrastructure\Persistence\DoctrineImportJobRepository;
use Doctrine\DBAL\DriverManager;

require dirname(__DIR__, 3).'/vendor/autoload.php';

/**
 * @var array{params: array{driver: 'pdo_pgsql', host: string, port: int, dbname: string, user: string, password: string}, schema: string, action: string} $input
 */
$input = json_decode(stream_get_contents(\STDIN), true, flags: \JSON_THROW_ON_ERROR);
$connection = DriverManager::getConnection($input['params']);
if (1 !== preg_match('/^rateset_test_[0-9a-f]{16}$/D', $input['schema'])) {
    throw new InvalidArgumentException('Некорректная тестовая схема.');
}
$connection->executeStatement(sprintf(
    <<<'SQL'
        SET search_path TO %s
        SQL,
    $input['schema'],
));
$connection->executeStatement(
    <<<'SQL'
        SELECT
            set_config('application_name', :name, false)
        SQL,
    ['name' => $input['schema']],
);
$connection->executeStatement(
    <<<'SQL'
        SET lock_timeout TO '10s'
        SQL,
);
$jobs = new DoctrineImportJobRepository($connection);
$result = match ($input['action']) {
    'claim' => null === $jobs->claim('2026-01-01', 0, 120) ? 0 : 1,
    'retry' => $jobs->retryErrors('2026-01-01'),
    'dispatch' => count($jobs->reserveDispatch(20, 600, 1)),
    'recover' => $jobs->recover(new App\Import\Domain\Entity\ImportJob(App\Shared\Domain\DatePointParser::parse('2026-01-01'), App\Import\Domain\Enum\RateImportStatus::Processing, 1, 0, leaseUntil: new Symfony\Component\Clock\DatePoint('-1 second')), 1, null) ? 1 : 0,
    default => throw new InvalidArgumentException('Неизвестный сценарий.'),
};
echo $result;
$connection->close();
