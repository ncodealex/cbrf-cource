<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Mapping;

use App\Cources\Domain\Entity\RateItem;
use App\Cources\Domain\Entity\RateSet;
use App\Shared\Domain\DatePointParser;
use App\Cources\Domain\ValueObject\CurrencyCode;
use BcMath\Number;

final readonly class RateSetMapper
{
    /** @return array{effectiveDate: string, items: list<array{code: string, sourceId: ?string, nominal: int, value: string, name: string, numCode: ?string}>} */
    public function toArray(RateSet $rateSet): array
    {
        $items = [];
        foreach ($rateSet->items as $item) {
            $items[] = [
                'code' => $item->code->value,
                'sourceId' => $item->sourceId,
                'nominal' => $item->nominal,
                'value' => (string) $item->value,
                'name' => $item->name,
                'numCode' => $item->numCode,
            ];
        }

        return ['effectiveDate' => $rateSet->date(), 'items' => $items];
    }

    public function fromArray(mixed $data): RateSet
    {
        try {
            if (!\is_array($data) || !isset($data['effectiveDate'], $data['items'])
                || !\is_string($data['effectiveDate']) || !\is_array($data['items']) || !array_is_list($data['items'])) {
                throw new InvalidRateSetPayload('Некорректная структура набора курсов');
            }
            $date = DatePointParser::parse($data['effectiveDate']);
            $items = [];
            foreach ($data['items'] as $item) {
                if (!\is_array($item)
                    || !isset($item['code'], $item['nominal'], $item['value'], $item['name'])
                    || !\is_string($item['code']) || !\is_int($item['nominal'])
                    || !\is_string($item['value']) || !\is_string($item['name'])
                    || !\array_key_exists('sourceId', $item) || !\array_key_exists('numCode', $item)
                    || !(null === $item['sourceId'] || \is_string($item['sourceId']))
                    || !(null === $item['numCode'] || \is_string($item['numCode']))
                    || 1 !== preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $item['value'])
                    || !is_numeric($item['value'])) {
                    throw new InvalidRateSetPayload('Некорректная структура котировки');
                }
                $items[] = new RateItem(
                    CurrencyCode::fromString($item['code']),
                    $item['sourceId'],
                    $item['nominal'],
                    new Number($item['value']),
                    $item['name'],
                    $item['numCode'],
                );
            }

            return new RateSet($date, $items);
        } catch (\InvalidArgumentException|\ValueError $e) {
            throw new InvalidRateSetPayload('Некорректные данные набора курсов: '.$e->getMessage(), 0, $e);
        }
    }
}
