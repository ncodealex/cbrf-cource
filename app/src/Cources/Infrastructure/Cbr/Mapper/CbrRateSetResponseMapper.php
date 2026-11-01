<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Cbr\Mapper;

use App\Cources\Application\Exception\CbrInvalidResponse;
use App\Cources\Domain\Entity\RateItem;
use App\Cources\Domain\Entity\RateSet;
use App\Cources\Domain\MoscowCalendar;
use App\Cources\Domain\ValueObject\CurrencyCode;
use App\Cources\Infrastructure\Cbr\Response\RateItemResponse;
use App\Cources\Infrastructure\Cbr\Response\RateSetResponse;
use BcMath\Number;

final readonly class CbrRateSetResponseMapper
{
    public function map(RateSetResponse $response): RateSet
    {
        try {
            $rawDate = trim($response->effectiveDate);
            $date    = MoscowCalendar::date($rawDate, str_contains($rawDate, '/') ? 'd/m/Y' : 'd.m.Y');
            $items   = [];
            foreach ($response->items as $item) {
                $items[] = $this->mapItem($item);
            }

            return new RateSet($date, $items);
        } catch (\InvalidArgumentException|\ValueError $e) {
            throw new CbrInvalidResponse('Некорректные данные Банка России: ' . $e->getMessage(), 0, $e);
        }
    }

    private function mapItem(RateItemResponse $item): RateItem
    {
        $code     = trim($item->code);
        $nominal  = trim($item->nominal);
        $value    = str_replace(',', '.', trim($item->value));
        $sourceId = trim($item->sourceId);
        $numCode  = null === $item->numCode ? '' : trim($item->numCode);
        if (1 !== preg_match('/^[A-Z]{3}$/D', $code)
            || false === filter_var($nominal, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            || 1 !== preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $value)
            || !is_numeric($value)
            || '' === $sourceId
            || ('' !== $numCode && 1 !== preg_match('/^[0-9]{3}$/D', $numCode))) {
            throw new CbrInvalidResponse('Некорректные реквизиты котировки ' . $code);
        }

        return new RateItem(
            CurrencyCode::fromString($code),
            $sourceId,
            (int)$nominal,
            new Number($value),
            trim($item->name),
            '' === $numCode ? null : $numCode,
        );
    }
}
