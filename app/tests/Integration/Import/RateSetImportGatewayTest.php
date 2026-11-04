<?php

declare(strict_types=1);

namespace App\Tests\Integration\Import;

use App\Courses\Infrastructure\Mapping\RateSetMapper;
use App\Shared\Contracts\Rates\RateSetImportGateway;
use App\Shared\Domain\DateFormatInterface;
use App\Shared\Domain\DatePointParser;
use App\Tests\Unit\Cources\Domain\RateSets;
use Doctrine\DBAL\Connection;
use Redis;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;
use const JSON_THROW_ON_ERROR;

final class RateSetImportGatewayTest extends KernelTestCase
{
    public function testPostgresConfirmationIsRequiredEvenWithCachedRateSet(): void
    {
        $container = self::getContainer();
        $db        = $container->get(Connection::class);
        $db->executeStatement(
            <<<'SQL'
                TRUNCATE rate_import_jobs,
                rate_request_dates,
                rate_sets CASCADE
                SQL,
        );
        $redis = $container->get(Redis::class);
        // Валидный, но другой кеш не должен определять подтверждение календарной даты.
        $redis->set('rateset:date:2026-10-24',
            json_encode(new RateSetMapper()->toArray(RateSets::saturday()), JSON_THROW_ON_ERROR));
        HttpClient::create()->request('POST', 'http://cbr-stub:8080/control', ['body' => 'ok'])->getContent();
        $container->get('limiter.cbr')->create('cbr.global')->reset();
        $gateway = $container->get(RateSetImportGateway::class);
        $date    = DatePointParser::parse('2026-10-24');
        self::assertFalse($gateway->isConfirmed($date));
        $gateway->ensureAvailable($date);
        self::assertTrue($gateway->isConfirmed($date));
        $gateway->ensureAvailable($date);
        self::assertCount(1, $gateway->confirmedDates($date->modify('-1 day'), $date));
        self::assertSame([], $gateway->confirmedDates($date->modify('+1 day'), $date->modify('+2 days')));
        self::assertSame('1992-07-03', $gateway->minRequestDate()->format(DateFormatInterface::ISO));
    }
}
