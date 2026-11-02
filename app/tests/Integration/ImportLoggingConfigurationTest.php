<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ImportLoggingConfigurationTest extends KernelTestCase
{
    public function testXmlOnlyReachesSpecializedFile(): void
    {
        $directory = sys_get_temp_dir().'/import-logs-'.bin2hex(random_bytes(8));
        mkdir($directory, 0o700);
        $previous = $_SERVER['APP_LOG_DIR'] ?? null;
        $_SERVER['APP_LOG_DIR'] = $directory;
        try {
            $logger = self::getContainer()->get('monolog.logger.cbrf');
            $logger->error('configuration_probe', ['xml' => '<private-xml/>', 'request_id' => 'probe']);
            foreach (['main' => false, 'error' => false, 'cbrf' => true] as $name => $hasXml) {
                $files = glob($directory.'/'.$name.'-*.log');
                self::assertIsArray($files);
                self::assertCount(1, $files);
                $text = file_get_contents($files[0]);
                self::assertIsString($text);
                self::assertStringContainsString('configuration_probe', $text);
                self::assertSame($hasXml, str_contains($text, 'private-xml'));
            }
        } finally {
            self::ensureKernelShutdown();
            if (null === $previous) {
                unset($_SERVER['APP_LOG_DIR']);
            } else {
                $_SERVER['APP_LOG_DIR'] = $previous;
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            } rmdir($directory);
        }
    }
}
