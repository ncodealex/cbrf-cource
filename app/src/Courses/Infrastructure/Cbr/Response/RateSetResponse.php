<?php

declare(strict_types=1);

namespace App\Courses\Infrastructure\Cbr\Response;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Ответ XML_daily без преобразования в доменные значения.
 */
#[Exclude]
final readonly class RateSetResponse
{
    /**
     * @param list<RateItemResponse> $items исходные котировки ЦБ
     */
    public function __construct(
        #[SerializedName('@Date')]
        public string $effectiveDate,
        #[SerializedName('Valute')]
        public array $items = [],
    ) {
    }
}
