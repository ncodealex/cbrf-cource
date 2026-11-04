<?php

declare(strict_types=1);

namespace App\Courses\Domain\Factory;

use App\Courses\Domain\CurrencyPair;
use App\Courses\Domain\Exception\InvalidCurrencyCode;
use App\Courses\Domain\ValueObject\CurrencyCode;

/**
 * Интерфейс фабрики создания валютной пары - нужен для того:
 *  - Чтобы в будущем иметь возможность провалидировать корректность ISO кода
 *  - Проверить - поддерживает ли провайдер валютную пару
 */
interface CurrencyPairFactoryInterface
{
    /**
     * Создать валютную пару из буквенных кодов; по умолчанию базовой валютой служит рубль.
     * Метод не запрашивает котировки и не проверяет поддержку пары внешним источником.
     *
     * @throws InvalidCurrencyCode если код пустой или имеет некорректный формат
     */
    public function of(
        string $currency,
        string $base = CurrencyCode::RUB,
    ): CurrencyPair;
}
