<?php

declare(strict_types=1);

namespace App\Courses\Application\Factory;

use App\Courses\Domain\CurrencyPair;
use App\Courses\Domain\Factory\CurrencyPairFactoryInterface;
use App\Courses\Domain\ValueObject\CurrencyCode;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(CurrencyPairFactoryInterface::class)]
final class CurrencyPairFactory implements CurrencyPairFactoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function of(
        string $currency,
        string $base = CurrencyCode::RUB,
    ): CurrencyPair {
        return new CurrencyPair(CurrencyCode::fromString($currency), CurrencyCode::fromString($base));
    }
}
