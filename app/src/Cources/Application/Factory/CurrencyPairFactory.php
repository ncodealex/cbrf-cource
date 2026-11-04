<?php

declare(strict_types=1);

namespace App\Cources\Application\Factory;

use App\Cources\Domain\CurrencyPair;
use App\Cources\Domain\Factory\CurrencyPairFactoryInterface;
use App\Cources\Domain\ValueObject\CurrencyCode;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(CurrencyPairFactoryInterface::class)]
final class CurrencyPairFactory implements CurrencyPairFactoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function of(string $currency, string $base = CurrencyCode::RUB): CurrencyPair
    {
        return new CurrencyPair(CurrencyCode::fromString($currency), CurrencyCode::fromString($base));
    }
}
