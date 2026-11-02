<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support\Logging;

use PHPUnit\Framework\TestCase;

final class MonologConfigurationTest extends TestCase
{
    public function testDevelopmentKeepsDebugInFilesWithoutFloodingStderr(): void
    {
        $config = require \dirname(__DIR__, 4).'/config/packages/monolog.php';
        self::assertSame('%env(APP_LOG_LEVEL)%', $config['monolog']['handlers']['main']['level']);
        self::assertSame('debug', $config['when@dev']['parameters']['env(APP_LOG_LEVEL)']);
        self::assertSame('%env(APP_STDERR_LOG_LEVEL)%', $config['monolog']['handlers']['stderr']['level']);
        self::assertSame('warning', $config['when@dev']['parameters']['env(APP_STDERR_LOG_LEVEL)']);
        self::assertSame('info', $config['parameters']['env(APP_STDERR_LOG_LEVEL)']);
        self::assertSame('error', $config['monolog']['handlers']['errors']['level']);
    }
}
