<?php

declare(strict_types=1);

use App\Cources\Application\Exception\RateSetConflict;
use App\Shared\Domain\DatePointParser;
use App\Cources\Infrastructure\Mapping\RateSetMapper;
use App\Cources\Infrastructure\Persistence\DoctrineRateSetRepository;
use App\Tests\Unit\Cources\Domain\RateSets;
use Doctrine\DBAL\DriverManager;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    /** @var array{params: array{driver: 'pdo_pgsql', host: string, port: int, dbname: string, user: string, password: string}, schema: string, requested: string, variant: string} $input параметры из родительского тестового процесса */
    $input = json_decode(stream_get_contents(\STDIN), true, 512, \JSON_THROW_ON_ERROR);
    $connection = DriverManager::getConnection($input['params']);
    $schema = $input['schema'];
    if (1 !== preg_match('/^rateset_test_[0-9a-f]{16}$/D', $schema)) {
        throw new InvalidArgumentException('Некорректная тестовая схема');
    }
    $connection->executeStatement('SET search_path TO '.$schema);
    $connection->executeStatement("SELECT set_config('application_name', :name, false)", ['name' => $schema]);
    $connection->executeStatement("SET lock_timeout TO '10s'");
    $set = match ($input['variant']) {
        'same' => RateSets::saturday(),
        'content' => RateSets::create('2026-10-24', [['USD', 'R01235', 1, '1.0000']]),
        'date' => RateSets::friday(),
        default => throw new InvalidArgumentException('Неизвестный сценарий'),
    };
    new DoctrineRateSetRepository($connection, new RateSetMapper())->save(DatePointParser::parse($input['requested']), $set);
    $connection->close();
    exit(0);
} catch (RateSetConflict) {
    exit(2);
} catch (Throwable $e) {
    fwrite(\STDERR, $e->getMessage());
    exit(1);
}
