<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support\Health\Endpoints;

use App\Support\Health\Application\ReadinessChecker;
use App\Support\Health\Endpoints\Console\ReadinessCommand;
use App\Tests\Unit\Support\Health\StubHealthProbe;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

final class ReadinessCommandTest extends TestCase
{
    public function testSuccessfulReadiness(): void
    {
        $tester = new CommandTester(new ReadinessCommand(new ReadinessChecker([StubHealthProbe::ok('postgres')], new NullLogger())));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('postgres: ok', $tester->getDisplay());
    }

    public function testFailureIsReportedWithoutExposingException(): void
    {
        $tester = new CommandTester(new ReadinessCommand(new ReadinessChecker([
            StubHealthProbe::failing('postgres', new \RuntimeException('secret')),
            StubHealthProbe::ok('redis'),
        ], new NullLogger())));

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('postgres: failed', $tester->getDisplay());
        self::assertStringContainsString('redis: ok', $tester->getDisplay());
        self::assertStringNotContainsString('secret', $tester->getDisplay());
    }
}
