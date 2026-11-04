<?php

declare(strict_types=1);

namespace App\Courses\Domain\Exception;

use App\Shared\Domain\Exception\SharedDomainInvalidArgument;

final class InvalidCurrencyCode extends SharedDomainInvalidArgument
{
    public function __construct(
        string $raw,
    ) {
        parent::__construct(
            \sprintf('Некорректный код валюты "%s": ожидаются три латинские буквы (ISO 4217)', $raw),
        );
    }
}
