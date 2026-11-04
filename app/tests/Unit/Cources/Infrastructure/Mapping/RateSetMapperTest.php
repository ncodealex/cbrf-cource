<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cources\Infrastructure\Mapping;

use App\Courses\Infrastructure\Mapping\InvalidRateSetPayload;
use App\Courses\Infrastructure\Mapping\RateSetMapper;
use App\Tests\Unit\Cources\Domain\RateSets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use const JSON_THROW_ON_ERROR;

final class RateSetMapperTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'не объект' => [null];
        yield 'пустой объект' => [[]];
        $data = new RateSetMapper()->toArray(RateSets::saturday());
        yield 'несуществующая дата' => [array_replace($data, ['effectiveDate' => '2026-02-31'])];
        yield 'пустой набор' => [array_replace($data, ['items' => []])];
        yield 'дубли' => [array_replace($data, ['items' => [$data['items'][0], $data['items'][0]]])];
        foreach ([
                     'code'     => 'RUB',
                     'nominal'  => 0,
                     'value'    => '0.0000',
                     'name'     => '',
                     'sourceId' => null,
                 ] as $field => $value) {
            yield 'инвариант ' . $field => [
                array_replace($data, ['items' => [array_replace($data['items'][0], [$field => $value])]]),
            ];
        }
        foreach ([
                     'nominal'  => '100',
                     'value'    => 81.45,
                     'code'     => [],
                     'sourceId' => 12,
                     'numCode'  => 840,
                 ] as $field => $value) {
            yield 'тип ' . $field => [
                array_replace($data, ['items' => [array_replace($data['items'][0], [$field => $value])]]),
            ];
        }
        foreach (['1e2', '1,50', 'NaN', '-1.2'] as $value) {
            yield 'число ' . $value => [
                array_replace($data, ['items' => [array_replace($data['items'][0], ['value' => $value])]]),
            ];
        }
        $item = $data['items'][0];
        unset($item['numCode']);
        yield 'отсутствует поле' => [array_replace($data, ['items' => [$item]])];
        yield 'не список' => [array_replace($data, ['items' => ['USD' => $data['items'][0]]])];
    }

    public function testRoundTripPreservesEveryFieldAndScale(): void
    {
        $mapper   = new RateSetMapper();
        $original = RateSets::saturday();
        $data     = $mapper->toArray($original);
        $restored = $mapper->fromArray(json_decode(json_encode($data, JSON_THROW_ON_ERROR), true, 512,
            JSON_THROW_ON_ERROR));

        self::assertSame($original->contentHash(), $restored->contentHash());
        self::assertSame($data, $mapper->toArray($restored));
        foreach ($original->items as $code => $item) {
            self::assertEquals($item, $restored->items[$code]);
            self::assertSame($item->value->scale, $restored->items[$code]->value->scale);
            self::assertSame((string)$item->value, (string)$restored->items[$code]->value);
        }
        self::assertSame('81.4500', (string)$restored->items['USD']->value);
        self::assertSame(10000, $restored->items['IDR']->nominal);
        self::assertSame(100, $restored->items['INR']->nominal);
    }

    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidPayload(mixed $payload): void
    {
        $this->expectException(InvalidRateSetPayload::class);
        new RateSetMapper()->fromArray($payload);
    }
}
