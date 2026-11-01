<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support\Health\Presentation;

use App\Support\Health\Application\HealthProbe;
use App\Support\Health\Application\ReadinessChecker;
use App\Support\Health\Presentation\Http\HealthController;
use App\Tests\Unit\Support\Health\StubHealthProbe;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;

final class HealthControllerTest extends TestCase
{
    public function testLivenessDoesNotTouchProbes(): void
    {
        $probe = $this->createMock(HealthProbe::class);
        $probe->expects(self::never())->method('check');
        $probe->expects(self::never())->method('isEnabled');

        $response = $this->controller($probe)->live();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'ok'], $this->decode($response));
    }

    public function testReadinessReturns200WhenDependenciesAreHealthy(): void
    {
        $response = $this->controller(StubHealthProbe::ok('postgres'), StubHealthProbe::ok('redis'))->ready();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['status' => 'ok', 'checks' => ['postgres' => 'ok', 'redis' => 'ok']],
            $this->decode($response),
        );
    }

    public function testReadinessReturns503WhenRequiredDependencyFails(): void
    {
        $response = $this->controller(
            StubHealthProbe::ok('postgres'),
            StubHealthProbe::failing('redis', new \RuntimeException('Connection refused')),
        )->ready();

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(
            ['status' => 'not_ready', 'checks' => ['postgres' => 'ok', 'redis' => 'failed']],
            $this->decode($response),
        );
        self::assertStringNotContainsString('Connection refused', (string) $response->getContent());
    }

    public function testResponsesAreJsonAndNotCacheable(): void
    {
        $response = $this->controller(StubHealthProbe::ok('postgres'))->ready();

        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    private function controller(HealthProbe ...$probes): HealthController
    {
        return new HealthController(new ReadinessChecker($probes, new NullLogger()));
    }

    /**
     * @return array<mixed>
     */
    private function decode(JsonResponse $response): array
    {
        $data = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
