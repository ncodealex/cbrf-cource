<?php

declare(strict_types=1);

use App\Cources\Application\Import\RateImportClaim;
use App\Cources\Infrastructure\Persistence\DoctrineRateImportJobRepository;
use Doctrine\DBAL\DriverManager;

require dirname(__DIR__, 2).'/vendor/autoload.php';

/** @var array{params: array{driver: 'pdo_pgsql', host: string, port: int, dbname: string, user: string, password: string}, schema: string, action: string} $input */
$input = json_decode(stream_get_contents(\STDIN), true, flags: \JSON_THROW_ON_ERROR);
$connection = DriverManager::getConnection($input['params']);
if (1 !== preg_match('/^rateset_test_[0-9a-f]{16}$/D', $input['schema'])) {
    throw new InvalidArgumentException('Некорректная тестовая схема.');
}
$connection->executeStatement('SET search_path TO '.$input['schema']);
$connection->executeStatement("SELECT set_config('application_name', :name, false)", ['name' => $input['schema']]);
$connection->executeStatement("SET lock_timeout TO '10s'");
$jobs = new DoctrineRateImportJobRepository($connection);
$result = match ($input['action']) {
    'claim' => null === $jobs->claim('2026-01-01', 0, 120) ? 0 : 1,
    'retry' => $jobs->retryErrors('2026-01-01'),
    'dispatch' => count($jobs->reserveDispatch(20, 600, 1)),
    'recover' => $jobs->recover(new RateImportClaim('2026-01-01', 1, 0), 1, null) ? 1 : 0,
    default => throw new InvalidArgumentException('Неизвестный сценарий.'),
};
echo $result;
$connection->close();
