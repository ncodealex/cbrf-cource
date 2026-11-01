<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support\Health\Application;

use App\Support\Health\Application\HealthProbe;
use App\Support\Health\Application\ReadinessChecker;
use App\Support\Health\Application\ReadinessReport;
use App\Tests\Unit\Support\Health\StubHealthProbe;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class ReadinessCheckerTest extends TestCase
{
    public function testReadyWhenAllProbesPass(): void
    {
        $report = $this->checker(StubHealthProbe::ok('postgres'), StubHealthProbe::ok('redis'))->check();

        self::assertTrue($report->isReady());
        self::assertSame(['postgres' => ReadinessReport::OK, 'redis' => ReadinessReport::OK], $report->checks);
    }

    public function testNotReadyWhenRequiredProbeFails(): void
    {
        $report = $this->checker(
            StubHealthProbe::ok('postgres'),
            StubHealthProbe::failing('redis', new \RuntimeException('Connection refused')),
        )->check();

        self::assertFalse($report->isReady());
        self::assertSame(['postgres' => ReadinessReport::OK, 'redis' => ReadinessReport::FAILED], $report->checks);
    }

    public function testFailureOfOneProbeDoesNotSkipOthers(): void
    {
        $report = $this->checker(
            StubHealthProbe::failing('postgres', new \RuntimeException('down')),
            StubHealthProbe::ok('redis'),
        )->check();

        self::assertSame(['postgres' => ReadinessReport::FAILED, 'redis' => ReadinessReport::OK], $report->checks);
    }

    public function testDisabledProbeIsNotExecuted(): void
    {
        $disabled = $this->createMock(HealthProbe::class);
        $disabled->method('isEnabled')->willReturn(false);
        $disabled->expects(self::never())->method('check');

        $report = $this->checker(StubHealthProbe::ok('postgres'), $disabled)->check();

        self::assertSame(['postgres' => ReadinessReport::OK], $report->checks);
    }

    public function testFailureIsLoggedWithoutExceptionMessage(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('Readiness probe failed', [
                'probe' => 'postgres',
                'error' => \RuntimeException::class,
                'code' => 7,
            ]);

        $checker = new ReadinessChecker(
            [StubHealthProbe::failing('postgres', new \RuntimeException('password=secret', 7))],
            $logger,
        );

        self::assertFalse($checker->check()->isReady());
    }

    private function checker(HealthProbe ...$probes): ReadinessChecker
    {
        return new ReadinessChecker($probes, new NullLogger());
    }
}
