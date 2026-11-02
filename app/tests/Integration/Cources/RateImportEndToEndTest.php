<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cources;

use App\Cources\Application\Message\ProcessRateImportMessage;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Process\Process;

final class RateImportEndToEndTest extends KernelTestCase
{
    private Connection $connection;
    private AmqpTransport $transport;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $transport = self::getContainer()->get('messenger.transport.rates_import');
        self::assertInstanceOf(AmqpTransport::class, $transport);
        $this->transport = $transport;
        $this->transport->setup();
        $this->connection->executeStatement('TRUNCATE rate_import_jobs, rate_request_dates, rate_sets CASCADE');
        foreach ($this->transport->get() as $message) {
            $this->transport->ack($message);
        }
        self::getContainer()->get(\Redis::class)->del('rateset:date:2026-10-21', 'rateset:date:2026-10-22', 'rateset:date:2026-10-23', 'rateset:date:2026-10-24');
        $factory = self::getContainer()->get('limiter.cbr');
        $factory->create('cbr.global')->reset();
        $this->control('ok');
    }

    private function control(string $mode): void
    {
        HttpClient::create()->request('POST', 'http://cbr-stub:8080/control', ['body' => $mode])->getContent();
    }

    private function console(string ...$arguments): Process
    {
        $process = new Process([\PHP_BINARY, 'bin/console', ...$arguments, '--no-interaction'], \dirname(__DIR__, 3));
        $process->setTimeout(20);
        $process->mustRun();

        return $process;
    }

    private function consume(int $limit = 1): void
    {
        $this->console('messenger:consume', 'rates_import', '--limit='.$limit, '--time-limit=5');
    }

    private function jobStatus(): string
    {
        $status = $this->connection->fetchOne('SELECT status FROM rate_import_jobs');
        self::assertIsString($status);

        return $status;
    }

    public function testImportDispatchWorkerAndProgress(): void
    {
        $this->console('app:rates:import', '--from=2026-10-24', '--to=2026-10-24');
        $this->console('app:rates:dispatch', '--once');
        $this->consume();
        self::assertSame('done', $this->jobStatus());
        $progress = $this->console('app:rates:import', '--from=2026-10-24', '--to=2026-10-24', '--progress', '--progress-timeout=2');
        self::assertStringContainsString('Подтверждено: 1/1', $progress->getOutput());
        // Дубликат старой версии подтверждается без повторного HTTP.
        $this->transport->send(new Envelope(new ProcessRateImportMessage('2026-10-24', 0)));
        $this->consume();
        $stats = HttpClient::create()->request('GET', 'http://cbr-stub:8080/')->toArray();
        self::assertSame(1, $stats['count']);
    }

    public function testPermanentErrorAndManualRetry(): void
    {
        $this->control('invalid');
        $this->console('app:rates:import', '--from=2026-10-23', '--to=2026-10-23');
        $this->console('app:rates:dispatch', '--once');
        $this->consume();
        self::assertSame('error', $this->jobStatus());
        $list = $this->console('app:rates:errors');
        self::assertStringContainsString('2026-10-23', $list->getOutput());
        $this->console('app:rates:retry-errors', '--date=2026-10-23');
        self::assertSame('waiting', $this->jobStatus());
        $this->control('ok');
        $this->console('app:rates:dispatch', '--once');
        $this->consume();
        // RateLimiter может отложить немедленный повтор; повторно делаем доступным после реального refill.
        if ('deferred' === $this->jobStatus()) {
            sleep(3);
            $this->connection->executeStatement('UPDATE rate_import_jobs SET available_at=NOW()');
            $this->console('app:rates:dispatch', '--once');
            $this->consume();
        }
        self::assertSame('done', $this->jobStatus());
    }

    public function test503IsDeferredAndRecovered(): void
    {
        $this->control('503');
        $this->console('app:rates:import', '--from=2026-10-22', '--to=2026-10-22');
        $this->console('app:rates:dispatch', '--once');
        $this->consume();
        self::assertSame('deferred', $this->jobStatus());
        sleep(3);
        $this->control('ok');
        $this->connection->executeStatement('UPDATE rate_import_jobs SET available_at=NOW()');
        $this->console('app:rates:dispatch', '--once');
        $this->consume();
        self::assertSame('done', $this->jobStatus());
    }

    public function testWatchStopsOnSignalAndRedispatchesLostPublication(): void
    {
        $this->console('app:rates:import', '--from=2026-10-21', '--to=2026-10-21');
        $this->connection->executeStatement("UPDATE rate_import_jobs SET dispatched_at=NOW()-INTERVAL '601 seconds'");
        $process = new Process([\PHP_BINARY, 'bin/console', 'app:rates:dispatch', '--watch'], \dirname(__DIR__, 3));
        $process->setTimeout(10);
        $process->start();
        try {
            $deadline = microtime(true) + 8;
            while (0 === $this->transport->getMessageCount() && microtime(true) < $deadline && $process->isRunning()) {
                usleep(10000);
            }
            self::assertGreaterThan(0, $this->transport->getMessageCount());
            $process->signal(\SIGTERM);
            $process->wait();
            self::assertSame(0, $process->getExitCode());
            $this->consume();
            self::assertSame('done', $this->jobStatus());
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }

    public function testTwoWorkersAcknowledgeDuplicateWithSingleHttpRequest(): void
    {
        $this->console('app:rates:import', '--from=2026-10-24', '--to=2026-10-24');
        $this->console('app:rates:dispatch', '--once');
        $this->transport->send(new Envelope(new ProcessRateImportMessage('2026-10-24', 0)));
        $workers = [];
        try {
            for ($i = 0; $i < 2; ++$i) {
                $worker = new Process([\PHP_BINARY, 'bin/console', 'messenger:consume', 'rates_import', '--limit=1', '--time-limit=5', '--no-interaction'], \dirname(__DIR__, 3));
                $worker->setTimeout(12);
                $worker->start();
                $workers[] = $worker;
            }
            foreach ($workers as $worker) {
                $worker->wait();
                self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            }
            self::assertSame('done', $this->jobStatus());
            $stats = HttpClient::create()->request('GET', 'http://cbr-stub:8080/')->toArray();
            self::assertSame(1, $stats['count']);
        } finally {
            foreach ($workers as $worker) {
                $worker->stop();
            }
        }
    }

    public function testGlobalLimiterDefersWithoutSpendingAttempt(): void
    {
        $this->console('app:rates:import', '--from=2026-10-22', '--to=2026-10-24');
        $this->console('app:rates:dispatch', '--once');
        $this->consume(3);
        self::assertSame(2, $this->connection->fetchOne("SELECT COUNT(*) FROM rate_import_jobs WHERE status = 'done'"));
        self::assertSame(1, $this->connection->fetchOne("SELECT COUNT(*) FROM rate_import_jobs WHERE status = 'deferred' AND attempts = 0"));
        sleep(3);
        $this->connection->executeStatement("UPDATE rate_import_jobs SET available_at=NOW() WHERE status = 'deferred'");
        $this->console('app:rates:dispatch', '--once');
        $this->consume();
        self::assertSame(3, $this->connection->fetchOne("SELECT COUNT(*) FROM rate_import_jobs WHERE status = 'done'"));
    }

    public function testRecoveryCompletesSavedRateSetWithoutHttp(): void
    {
        $this->console('app:rates:import', '--from=2026-10-24', '--to=2026-10-24');
        $jobs = new \App\Cources\Infrastructure\Persistence\DoctrineRateImportJobRepository($this->connection);
        self::assertNotNull($jobs->claim('2026-10-24', 0, 120));
        $repository = new \App\Cources\Infrastructure\Persistence\DoctrineRateSetRepository($this->connection, new \App\Cources\Infrastructure\Mapping\RateSetMapper());
        $repository->save(\App\Shared\Domain\DatePointParser::parse('2026-10-24'), \App\Tests\Unit\Cources\Domain\RateSets::saturday());
        $this->connection->executeStatement("UPDATE rate_import_jobs SET lease_until=NOW()-INTERVAL '1 second'");
        $this->console('app:rates:dispatch', '--once');
        $this->consume();
        self::assertSame('done', $this->jobStatus());
        $stats = HttpClient::create()->request('GET', 'http://cbr-stub:8080/')->toArray();
        self::assertSame(0, $stats['count']);
    }

    public function testCrashedWorkerIsRecoveredWithNewVersion(): void
    {
        $this->control('delay');
        $worker = $this->startDelayedWorker('2026-10-20');
        try {
            $worker->signal(\SIGKILL);
            $worker->wait();
            $this->connection->executeStatement("UPDATE rate_import_jobs SET lease_until=NOW()-INTERVAL '1 second'");
            $this->console('app:rates:dispatch', '--once');
            self::assertSame('deferred', $this->jobStatus());
            self::assertSame(2, $this->connection->fetchOne('SELECT version FROM rate_import_jobs'));
            self::assertSame(1, $this->connection->fetchOne('SELECT attempts FROM rate_import_jobs'));
        } finally {
            $worker->stop();
        }
    }

    public function testRefreshFencesActiveWorkerAndPreservesConfirmedMapping(): void
    {
        $this->control('delay');
        $worker = $this->startDelayedWorker();
        try {
            $jobs = new \App\Cources\Infrastructure\Persistence\DoctrineRateImportJobRepository($this->connection);
            $repository = new \App\Cources\Infrastructure\Persistence\DoctrineRateSetRepository($this->connection, new \App\Cources\Infrastructure\Mapping\RateSetMapper());
            $storage = new \App\Cources\Infrastructure\Persistence\DoctrineRateSetRefreshStorage($this->connection, $jobs, $repository);
            $storage->invalidate(\App\Shared\Domain\DatePointParser::parse('2026-10-24'));
            $worker->wait();
            self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            // Старый HTTP может завершиться, но не может завершить новую версию задания.
            self::assertSame('waiting', $this->jobStatus());
            self::assertSame(2, $this->connection->fetchOne('SELECT version FROM rate_import_jobs'));
            self::assertTrue($jobs->isConfirmed('2026-10-24'));
            $this->console('app:rates:dispatch', '--once');
            $this->consume();
            self::assertSame('done', $this->jobStatus());
        } finally {
            $worker->stop();
        }
    }

    private function startDelayedWorker(string $date = '2026-10-24'): Process
    {
        $this->console('app:rates:import', '--from='.$date, '--to='.$date);
        $this->console('app:rates:dispatch', '--once');
        $worker = new Process([\PHP_BINARY, 'bin/console', 'messenger:consume', 'rates_import', '--limit=1', '--time-limit=8', '--no-interaction'], \dirname(__DIR__, 3));
        $worker->setTimeout(12);
        $worker->start();
        $deadline = microtime(true) + 6;
        while ('processing' !== $this->jobStatus() && $worker->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        if ('processing' !== $this->jobStatus()) {
            $worker->stop();
            self::fail('Worker не захватил задание.');
        }

        return $worker;
    }

    public function testUnhandledDatabaseFailureUsesMessengerFailureTransport(): void
    {
        $this->console('app:rates:import', '--from=2026-10-24', '--to=2026-10-24');
        $this->transport->send(new Envelope(new ProcessRateImportMessage('2026-10-24', 0)));
        $this->connection->executeStatement("CREATE FUNCTION reject_import_update() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''Тестовая недоступность хранения''; END'");
        $this->connection->executeStatement('CREATE TRIGGER reject_import_update BEFORE UPDATE ON rate_import_jobs FOR EACH ROW EXECUTE FUNCTION reject_import_update()');
        try {
            $process = $this->console('messenger:consume', 'rates_import', '--limit=4', '--time-limit=18', '-vv');
            $failed = self::getContainer()->get('messenger.transport.failed');
            self::assertInstanceOf(AmqpTransport::class, $failed);
            $found = false;
            foreach ($failed->get() as $envelope) {
                if ($envelope->getMessage() instanceof ProcessRateImportMessage) {
                    $found = true;
                }
                $failed->ack($envelope);
            }
            self::assertTrue($found, 'Необработанная ошибка хранения должна попасть в failed transport. '.$process->getOutput().$process->getErrorOutput());
            self::assertSame('waiting', $this->jobStatus());
        } finally {
            $this->connection->executeStatement('DROP TRIGGER reject_import_update ON rate_import_jobs');
            $this->connection->executeStatement('DROP FUNCTION reject_import_update()');
        }
    }

    public function testUnacknowledgedMessageIsRedeliveredAfterReconnect(): void
    {
        $this->transport->send(new Envelope(new ProcessRateImportMessage('2026-10-24', 71)));
        $first = null;
        foreach ($this->transport->get() as $envelope) {
            $first = $envelope;
            break;
        }
        self::assertNotNull($first);
        $this->transport->close();
        $this->transport->setup();
        $second = null;
        $deadline = microtime(true) + 3;
        do {
            foreach ($this->transport->get() as $envelope) {
                $second = $envelope;
                break;
            }
            if (null === $second) {
                usleep(10000);
            }
        } while (null === $second && microtime(true) < $deadline);
        self::assertNotNull($second);
        $message = $second->getMessage();
        self::assertInstanceOf(ProcessRateImportMessage::class, $message);
        self::assertSame(71, $message->version);
        $this->transport->ack($second);
        self::assertSame(0, $this->transport->getMessageCount());
    }
}
