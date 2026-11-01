<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Cbr\Serializer;

use App\Cources\Infrastructure\Cbr\Response\RateSetResponse;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/** XmlEncoder представляет единственный Valute объектом, несколько — списком. */
final readonly class RateSetResponseDenormalizer implements DenormalizerInterface
{
    public function __construct(
        #[Autowire(service: 'serializer.normalizer.object')]
        private DenormalizerInterface $normalizer,
    ) {
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): RateSetResponse
    {
        if (!\is_array($data)) {
            throw new NotNormalizableValueException('Ответ ЦБ должен содержать дату и котировки');
        }
        if (\array_key_exists('Valute', $data)) {
            if (!\is_array($data['Valute'])) {
                throw new NotNormalizableValueException('Элемент Valute должен содержать поля котировки');
            }
            if (!array_is_list($data['Valute'])) {
                $data['Valute'] = [$data['Valute']];
            }
        }

        return $this->normalizer->denormalize($data, RateSetResponse::class, $format, $context);
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return RateSetResponse::class === $type && 'xml' === $format;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [RateSetResponse::class => 'xml' === $format];
    }
}
