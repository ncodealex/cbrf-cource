<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

final class CbrStubTest extends TestCase
{
    public function testRecordedResponseIsSentWithoutRecoding(): void
    {
        $client = HttpClient::createForBaseUri('http://cbr-stub:8080');
        $client->request('POST', '/control', ['body' => 'ok'])->getContent();
        $response = $client->request('GET', '/scripts/XML_daily.asp', ['query' => ['date_req' => '18/10/2026']]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(file_get_contents(__DIR__.'/../Fixtures/Cbr/recorded/2026-10-18.xml'), $response->getContent());
        self::assertStringContainsString('windows-1251', $response->getHeaders()['content-type'][0]);
    }

    public function testInvalidAndMissingDatesDoNotReturnUnrelatedRates(): void
    {
        $client = HttpClient::createForBaseUri('http://cbr-stub:8080');
        $client->request('POST', '/control', ['body' => 'ok'])->getContent();
        foreach (['bad', '31/02/2026'] as $date) {
            $response = $client->request('GET', '/scripts/XML_daily.asp', ['query' => ['date_req' => $date]]);
            self::assertSame(400, $response->getStatusCode());
        }
        $response = $client->request('GET', '/scripts/XML_daily.asp', ['query' => ['date_req' => '01/01/2000']]);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(200, $client->request('GET', '/health')->getStatusCode());
    }
}
