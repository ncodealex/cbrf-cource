<?php

declare(strict_types=1);

namespace App\Courses\Domain\ValueObject;

use App\Courses\Domain\Exception\InvalidCurrencyCode;

/**
 * Буквенный код валюты ISO 4217. Ввод регистронезависимый, RUR и RUB — российский рубль.
 */
final readonly class CurrencyCode implements \Stringable
{
    public const string  RUB = 'RUB';
    private const string RUR = 'RUR';

    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $raw): self
    {
        $code = strtoupper(trim($raw));
        if (1 !== preg_match('/^[A-Z]{3}$/', $code)) {
            throw new InvalidCurrencyCode($raw);
        }

        return new self(self::RUR === $code ? self::RUB : $code);
    }

    public static function rub(): self
    {
        return new self(self::RUB);
    }

    public function isRuble(): bool
    {
        return self::RUB === $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
