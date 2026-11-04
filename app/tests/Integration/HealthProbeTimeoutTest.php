<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Support\Health\Infrastructure\RedisHealthProbe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class HealthProbeTimeoutTest extends TestCase
{
    public function testRedisReadTimeoutInterruptsStalledDependency(): void
    {
        $server = new Process([\PHP_BINARY, \dirname(__DIR__).'/Fixtures/stalled-server.php']);
        $server->start();
        try {
            self::assertTrue($server->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "\n")));
            $address = trim($server->getOutput());
            $probe = new RedisHealthProbe('redis://'.$address, ['timeout' => 1.0, 'read_timeout' => 1.0]);
            $start = microtime(true);
            $failure = null;
            try {
                $probe->check();
            } catch (\Throwable $exception) {
                $failure = $exception;
            }
            self::assertNotNull($failure, 'Зависшая зависимость должна завершиться ошибкой.');
            self::assertLessThan(3.5, microtime(true) - $start);
        } finally {
            $server->stop(0);
        }
    }
}
