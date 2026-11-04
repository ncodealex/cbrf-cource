<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ImportLoggingConfigurationTest extends KernelTestCase
{
    public function testXmlOnlyReachesSpecializedFile(): void
    {
        $kernel = self::bootKernel();
        $probe = 'configuration_probe_'.bin2hex(random_bytes(8));
        $logger = self::getContainer()->get('monolog.logger.cbrf');
        $logger->error($probe, ['xml' => '<private-xml/>', 'request_id' => $probe]);

        foreach (['main' => false, 'error' => false, 'cbrf' => true] as $name => $hasXml) {
            $matches = [];
            foreach (glob($kernel->getLogDir().'/'.$name.'-*.log') ?: [] as $file) {
                foreach (file($file, \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                    if (str_contains($line, $probe)) {
                        $matches[] = $line;
                    }
                }
            }
            self::assertCount(1, $matches);
            self::assertSame($hasXml, str_contains($matches[0], 'private-xml'));
        }
    }
}
