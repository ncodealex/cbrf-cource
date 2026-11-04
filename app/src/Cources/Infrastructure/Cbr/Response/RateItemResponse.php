<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Cbr\Response;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Исходная котировка ЦБ; числовые поля сохраняются строками до проверки.
 */
#[Exclude]
final readonly class RateItemResponse
{
    public function __construct(
        #[SerializedName('@ID')]
        public string $sourceId,
        #[SerializedName('CharCode')]
        public string $code,
        #[SerializedName('Nominal')]
        public string $nominal,
        #[SerializedName('Value')]
        public string $value,
        #[SerializedName('Name')]
        public string $name,
        #[SerializedName('NumCode')]
        public ?string $numCode = null,
    ) {
    }
}
