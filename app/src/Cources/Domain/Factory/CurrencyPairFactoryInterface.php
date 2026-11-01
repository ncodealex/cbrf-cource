<?php
declare(strict_types=1);

namespace App\Cources\Domain\Factory;

use App\Cources\Domain\CurrencyPair;
use App\Cources\Domain\ValueObject\CurrencyCode;

/**
 * Интерфейс фабрики создания валютной пары - нужен для того:
 *  - Чтобы в будущем иметь возможность провалидировать корректность ISO кода
 *  - Проверить - поддерживает ли провайдер валютную пару
 */
interface CurrencyPairFactoryInterface
{
    public function of(string $currency, string $base = CurrencyCode::RUB): CurrencyPair;
}
