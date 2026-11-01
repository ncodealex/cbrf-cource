<?php

declare(strict_types=1);

namespace App\Tests\Application\Support\Health;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HTTP-запросы через ядро Symfony. Недоступность инфраструктуры моделируется
 * подменой адреса на закрытый порт: env-переменные читаются при создании сервисов.
 */
final class HealthEndpointsTest extends WebTestCase
{
    private const string UNREACHABLE_REDIS_URL = 'redis://127.0.0.1:1';

    /** @var array<string, mixed> */
    private array $originalEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
        $this->originalEnv = [];

        parent::tearDown();
    }

    public function testLivenessReturnsOk(): void
    {
        $client = self::createClient();
        $client->request('GET', '/health/live');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(['status' => 'ok'], $this->json($client));
    }

    public function testLivenessDoesNotDependOnInfrastructure(): void
    {
        $this->overrideEnv('POSTGRES_HOST', '127.0.0.1');
        $this->overrideEnv('POSTGRES_PORT', '1');
        $this->overrideEnv('REDIS_URL', self::UNREACHABLE_REDIS_URL);

        $client = self::createClient();
        $client->request('GET', '/health/live');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['status' => 'ok'], $this->json($client));
    }

    public function testReadinessReturns503WhenDependenciesAreUnavailable(): void
    {
        $this->overrideEnv('POSTGRES_HOST', '127.0.0.1');
        $this->overrideEnv('POSTGRES_PORT', '1');
        $this->overrideEnv('REDIS_URL', self::UNREACHABLE_REDIS_URL);

        $client = self::createClient();
        $client->request('GET', '/health/ready');

        self::assertResponseStatusCodeSame(503);
        self::assertSame(
            ['status' => 'not_ready', 'checks' => ['postgres' => 'failed', 'redis' => 'failed']],
            $this->json($client),
        );
    }

    public function testReadinessReturns200WithHealthyInfrastructure(): void
    {
        $client = self::createClient();
        $client->request('GET', '/health/ready');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['status' => 'ok', 'checks' => ['postgres' => 'ok', 'redis' => 'ok']],
            $this->json($client),
        );
    }

    public function testUnknownMethodIsRejected(): void
    {
        $client = self::createClient();
        $client->request('POST', '/health/live');

        self::assertResponseStatusCodeSame(405);
    }

    private function overrideEnv(string $name, string $value): void
    {
        $this->originalEnv[$name] ??= $_SERVER[$name] ?? null;
        $_SERVER[$name] = $_ENV[$name] = $value;
    }

    /**
     * @return array<mixed>
     */
    private function json(KernelBrowser $client): array
    {
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
