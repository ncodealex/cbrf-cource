<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Support\Health\Infrastructure\RabbitMqHealthProbe;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;
use Symfony\Component\Messenger\Envelope;

/**
 * Smoke-проверки реальных подключений из контейнера приложения.
 */
final class InfrastructureSmokeTest extends KernelTestCase
{
    public function testApplicationClockUsesMoscowTimezone(): void
    {
        $clock = self::getContainer()->get(ClockInterface::class);

        self::assertSame('Europe/Moscow', date_default_timezone_get());
        self::assertSame('Europe/Moscow', $clock->now()->getTimezone()->getName());
    }

    public function testPostgresAcceptsQueries(): void
    {
        $connection = self::getContainer()->get(Connection::class);

        self::assertEquals(1, $connection->fetchOne('SELECT 1'));

        $version = $connection->fetchOne('SHOW server_version');
        self::assertIsString($version);
        self::assertStringStartsWith('18.', $version);
    }

    public function testRedisCacheBackendStoresValues(): void
    {
        $pool = self::getContainer()->get('cache.app');

        $key = 'smoke_'.bin2hex(random_bytes(4));
        $pool->save($pool->getItem($key)->set('value')->expiresAfter(10));

        try {
            self::assertSame('value', $pool->getItem($key)->get());
        } finally {
            $pool->deleteItem($key);
        }

        self::assertNotFalse(self::getContainer()->get(\Redis::class)->ping());
    }

    public function testRabbitMqAcceptsConnections(): void
    {
        self::getContainer()->get(RabbitMqHealthProbe::class)->check();
        $this->addToAssertionCount(1);
    }

    public function testMessengerTransportsAreDeclaredInRabbitMq(): void
    {
        foreach (['messenger.transport.async', 'messenger.transport.failed', 'messenger.transport.rates_import'] as $id) {
            $transport = self::getContainer()->get($id);
            self::assertInstanceOf(AmqpTransport::class, $transport);

            // Идемпотентно объявляет durable exchange/очередь; сообщения не отправляются.
            $transport->setup();
            self::assertGreaterThanOrEqual(0, $transport->getMessageCount());
        }
    }

    public function testBothTransportsPublishAndAcknowledgeMessages(): void
    {
        foreach (['messenger.transport.async', 'messenger.transport.failed', 'messenger.transport.rates_import'] as $id) {
            $transport = self::getContainer()->get($id);
            self::assertInstanceOf(AmqpTransport::class, $transport);
            $transport->setup();

            $message = new \stdClass();
            $message->probeId = bin2hex(random_bytes(8));
            $transport->send(new Envelope($message));

            $received = null;
            foreach ($transport->get() as $envelope) {
                $received = $envelope;
                break;
            }
            self::assertNotNull($received);

            try {
                self::assertEquals($message, $received->getMessage());
            } finally {
                $transport->ack($received);
            }
        }
    }
}
