<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Cbr\Serializer;

use App\Cources\Infrastructure\Cbr\Response\RateSetResponse;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * XmlEncoder представляет единственный Valute объектом, несколько — списком.
 */
final readonly class RateSetResponseDenormalizer implements DenormalizerInterface
{
    /**
     * В старых ответах XML_daily ЦБ не всегда передавал ISO-коды валют.
     * Ключом служит стабильный идентификатор валюты самого ЦБ.
     *
     * @var array<string, array{CharCode: string, NumCode: string}>
     */
    private const array LEGACY_CURRENCY_CODES = [
        'R01720A' => ['CharCode' => 'UAK', 'NumCode' => '804'],
    ];

    /**
     * @var list<string>
     */
    private const array REQUIRED_ITEM_FIELDS = ['@ID', 'CharCode', 'Nominal', 'Value', 'Name'];

    public function __construct(
        #[Autowire(service: 'serializer.normalizer.object')]
        private DenormalizerInterface $normalizer,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function denormalize(
        mixed $data,
        string $type,
        ?string $format = null,
        array $context = [],
    ): RateSetResponse {
        if (!\is_array($data)) {
            throw new NotNormalizableValueException('Ответ ЦБ должен содержать дату и котировки');
        }
        if (\array_key_exists('Valute', $data)) {
            if (!\is_array($data['Valute'])) {
                if ('' === $data['Valute'] || null === $data['Valute']) {
                    $data['Valute'] = [];
                } else {
                    throw new NotNormalizableValueException('Элемент Valute должен содержать поля котировки');
                }
            }
            if (!array_is_list($data['Valute'])) {
                $data['Valute'] = [$data['Valute']];
            }
            foreach ($data['Valute'] as &$item) {
                if (!\is_array($item) || !isset($item['@ID']) || !\is_string($item['@ID'])) {
                    continue;
                }
                $legacyCodes = self::LEGACY_CURRENCY_CODES[$item['@ID']] ?? null;
                if (null === $legacyCodes) {
                    continue;
                }
                $item += $legacyCodes;
            }
            unset($item);
            $data['Valute'] = array_values(array_filter(
                $data['Valute'],
                static function (mixed $item): bool {
                    if (!\is_array($item)) {
                        return true;
                    }
                    foreach (self::REQUIRED_ITEM_FIELDS as $field) {
                        if (!\array_key_exists($field, $item)) {
                            return false;
                        }
                    }

                    return true;
                },
            ));
            if ([] === $data['Valute']) {
                unset($data['Valute']);
            }
        }

        return $this->normalizer->denormalize($data, RateSetResponse::class, $format, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function supportsDenormalization(
        mixed $data,
        string $type,
        ?string $format = null,
        array $context = [],
    ): bool {
        return RateSetResponse::class === $type && 'xml' === $format;
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedTypes(?string $format): array
    {
        return [RateSetResponse::class => 'xml' === $format];
    }
}
