<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support\Logging;

use PHPUnit\Framework\TestCase;

final class MonologConfigurationTest extends TestCase
{
    public function testDevelopmentKeepsDebugInFilesWithoutFloodingStderr(): void
    {
        /**
         * @var array{monolog: array{handlers: array<string, array{level: string}>}, parameters: array<string, string>, 'when@dev': array{parameters: array<string, string>}} $config
         */
        $config = require \dirname(__DIR__, 4).'/config/packages/monolog.php';
        self::assertSame('%env(default:app.logging.default_level:APP_LOG_LEVEL)%', $config['monolog']['handlers']['main']['level']);
        self::assertSame('debug', $config['when@dev']['parameters']['app.logging.default_level']);
        self::assertSame('%env(default:app.logging.default_stderr_level:APP_STDERR_LOG_LEVEL)%', $config['monolog']['handlers']['stderr']['level']);
        self::assertSame('warning', $config['when@dev']['parameters']['app.logging.default_stderr_level']);
        self::assertSame('info', $config['parameters']['app.logging.default_stderr_level']);
        self::assertSame('error', $config['monolog']['handlers']['errors']['level']);
    }
}
