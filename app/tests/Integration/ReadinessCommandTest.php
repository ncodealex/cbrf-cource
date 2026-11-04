<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

final class ReadinessCommandTest extends KernelTestCase
{
    public function testPendingMigrationMakesCommandFail(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $version = 'DoctrineMigrations\\Version20261030000000';
        $connection->executeStatement(
            <<<'SQL'
                UPDATE doctrine_migration_versions
                SET version = :temporary
                WHERE version = :version
                SQL,
            ['temporary' => $version.'_health_test', 'version' => $version],
        );
        try {
            $process = $this->command();
            self::assertSame(1, $process->getExitCode(), $process->getErrorOutput());
            self::assertStringContainsString('migrations: failed', $process->getOutput());
            self::assertStringContainsString('postgres: ok', $process->getOutput());
        } finally {
            $connection->executeStatement(
                <<<'SQL'
                    UPDATE doctrine_migration_versions
                    SET version = :version
                    WHERE version = :temporary
                    SQL,
                ['temporary' => $version.'_health_test', 'version' => $version],
            );
        }
        $process = $this->command();
        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('migrations: ok', $process->getOutput());
    }

    public function testRedisFailureAndOptionalRabbitMqCheck(): void
    {
        $process = new Process([\PHP_BINARY, 'bin/console', 'app:health:ready'], \dirname(__DIR__, 2), [
            'REDIS_URL' => 'redis://127.0.0.1:1',
            'HEALTH_CHECK_RABBITMQ' => '1',
        ]);
        $process->setTimeout(12);
        $process->run();

        self::assertSame(1, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('redis: failed', $process->getOutput());
        self::assertStringContainsString('postgres: ok', $process->getOutput());
        self::assertStringContainsString('rabbitmq: ok', $process->getOutput());
    }

    private function command(): Process
    {
        $process = new Process([\PHP_BINARY, 'bin/console', 'app:health:ready'], \dirname(__DIR__, 2));
        $process->setTimeout(12);
        $process->run();

        return $process;
    }
}
