<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Support\Health\Infrastructure\ProbeProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class HealthProbeTimeoutTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function dependencies(): iterable
    {
        yield 'SQL after successful connection' => ['postgres'];
        yield 'AMQP handshake' => ['rabbitmq'];
        yield 'Redis socket read' => ['redis'];
    }

    #[DataProvider('dependencies')]
    public function testDeadlineInterruptsStalledDependency(string $name): void
    {
        $projectDir = \dirname(__DIR__, 2);
        $server = new Process([\PHP_BINDIR.'/php', $projectDir.'/tests/Fixtures/stalled-server.php', $name]);
        $server->setTimeout(5);
        $server->start();

        try {
            self::assertTrue($server->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "\n")));
            $address = trim($server->getOutput());
            $port = (int) substr($address, (int) strrpos($address, ':') + 1);
            $parameters = match ($name) {
                'postgres' => ['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'port' => $port, 'dbname' => 'test', 'user' => 'test', 'password' => 'test', 'charset' => 'utf8'],
                'redis' => ['dsn' => 'redis://127.0.0.1:'.$port, 'options' => ['timeout' => 5.0, 'read_timeout' => 5.0]],
                default => ['dsn' => 'amqp://127.0.0.1:'.$port.'/%2f', 'options' => ['connect_timeout' => 5.0, 'read_timeout' => 5.0]],
            };
            $start = microtime(true);

            try {
                new ProbeProcess($projectDir, 1.0)->check($name, $parameters);
                self::fail('A stalled dependency must reach the process deadline.');
            } catch (ProcessTimedOutException) {
                self::assertLessThan(2.0, microtime(true) - $start);
            }

            if ('postgres' === $name) {
                self::assertStringContainsString('query', $server->getOutput());
            }
        } finally {
            $server->stop(0);
        }
    }
}
