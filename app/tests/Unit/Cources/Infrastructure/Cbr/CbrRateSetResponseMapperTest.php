<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cources\Infrastructure\Cbr;

use App\Courses\Infrastructure\Cbr\Exception\CbrInvalidResponse;
use App\Courses\Infrastructure\Cbr\Mapper\CbrRateSetResponseMapper;
use App\Courses\Infrastructure\Cbr\Response\RateItemResponse;
use App\Courses\Infrastructure\Cbr\Response\RateSetResponse;
use App\Tests\Fixtures\Cbr\CbrFixtures;
use App\Tests\Fixtures\Cbr\CbrSerializer;
use App\Tests\Unit\Cources\Domain\RateSets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\XmlEncoder;

final class CbrRateSetResponseMapperTest extends TestCase
{
    /**
     * @return iterable<array{string}>
     */
    public static function effectiveDates(): iterable
    {
        foreach ([
                     '03.07.2026',
                     '3.07.2026',
                     '3.7.2026',
                     '03/07/2026',
                     '3/07/2026',
                     '3/7/2026',
                     ' 3.7.2026 ',
                     '2026-07-03',
                     '3-7-2026',
                 ] as $value) {
            yield [$value];
        }
    }

    /**
     * @return iterable<string, array{RateSetResponse}>
     */
    public static function invalidResponses(): iterable
    {
        $item = new RateItemResponse('R01235', 'USD', '1', '81,4500', 'Доллар США', '840');
        yield 'неверная дата' => [new RateSetResponse('31.02.2026', [$item])];
        yield 'пустой набор' => [new RateSetResponse('24.10.2026')];
        yield 'дубли валют' => [new RateSetResponse('24.10.2026', [$item, $item])];
        foreach ([
                     ['R01235', 'RUB', '1', '1,0000', 'Рубль', '643'],
                     ['', 'USD', '1', '81,4500', 'Доллар США', '840'],
                     ['R01235', 'usd', '1', '81,4500', 'Доллар США', '840'],
                     ['R01235', 'USD', '0', '81,4500', 'Доллар США', '840'],
                     ['R01235', 'USD', '1.5', '81,4500', 'Доллар США', '840'],
                     ['R01235', 'USD', '999999999999999999999999', '81,4500', 'Доллар США', '840'],
                     ['R01235', 'USD', '1', '0,0000', 'Доллар США', '840'],
                     ['R01235', 'USD', '1', '-1', 'Доллар США', '840'],
                     ['R01235', 'USD', '1', '1e2', 'Доллар США', '840'],
                     ['R01235', 'USD', '1', '81,4500', ' ', '840'],
                     ['R01235', 'USD', '1', '81,4500', 'Доллар США', '84'],
                 ] as $index => $fields) {
            yield 'неверная котировка ' . $index => [
                new RateSetResponse('24.10.2026', [new RateItemResponse(...$fields)]),
            ];
        }
    }

    #[DataProvider('effectiveDates')]
    public function testMapsSupportedDateFormats(string $value): void
    {
        $response = new RateSetResponse($value, [
            new RateItemResponse('R01235', 'USD', '1', '81,4500', 'Доллар США', '840'),
        ]);

        $set = new CbrRateSetResponseMapper()->map($response);

        self::assertSame('2026-07-03 00:00:00 Europe/Moscow', $set->effectiveDate->format('Y-m-d H:i:s e'));
    }

    public function testDeserializesTypedCollectionAndMapsCompleteSet(): void
    {
        $response = CbrSerializer::create()->deserialize(
            CbrFixtures::load('daily_2026-10-24.xml'),
            RateSetResponse::class,
            'xml',
            [
                XmlEncoder::TYPE_CAST_ATTRIBUTES => false,
            ],
        );
        self::assertCount(5, $response->items);
        self::assertSame('24.10.2026', $response->effectiveDate);
        self::assertContainsOnlyInstancesOf(RateItemResponse::class, $response->items);
        self::assertSame('1', $response->items[0]->nominal);
        self::assertSame(
            RateSets::saturday()->contentHash(),
            new CbrRateSetResponseMapper()->map($response)->contentHash(),
        );
    }

    public function testPreservesNumericStringsAndTrailingZeros(): void
    {
        $xml = '<ValCurs Date="24.10.2026"><Valute ID="0001"><NumCode>036</NumCode><CharCode>AUD</CharCode><Nominal>100</Nominal><Name>Австралийских долларов</Name><Value>8500,000000</Value></Valute></ValCurs>';
        $response = CbrSerializer::create()->deserialize(
            $xml,
            RateSetResponse::class,
            'xml',
            [XmlEncoder::TYPE_CAST_ATTRIBUTES => false],
        );
        self::assertCount(1, $response->items);
        $item = $response->items[0];
        self::assertSame('0001', $item->sourceId);
        self::assertSame('036', $item->numCode);
        self::assertSame('100', $item->nominal);
        self::assertSame('8500,000000', $item->value);
        $mapped = new CbrRateSetResponseMapper()->map($response)->items['AUD'];
        self::assertSame(100, $mapped->nominal);
        self::assertSame('8500.000000', (string)$mapped->value);
        self::assertSame(6, $mapped->value->scale);
    }

    #[DataProvider('invalidResponses')]
    public function testRejectsInvalidResponse(RateSetResponse $response): void
    {
        $this->expectException(CbrInvalidResponse::class);
        new CbrRateSetResponseMapper()->map($response);
    }
}
