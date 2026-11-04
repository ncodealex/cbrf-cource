<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Cbr;

use App\Cources\Infrastructure\Cbr\Serializer\RateSetResponseDenormalizer;
use Symfony\Component\PropertyInfo\Extractor\ConstructorExtractor;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Serializer с теми же механизмами атрибутов и типов, что использует FrameworkBundle.
 */
final class CbrSerializer
{
    public static function create(): Serializer
    {
        $metadata = new ClassMetadataFactory(new AttributeLoader());
        $phpDoc = new PhpDocExtractor();
        $reflection = new ReflectionExtractor();
        $types = new PropertyInfoExtractor(typeExtractors: [new ConstructorExtractor([$phpDoc, $reflection]), $phpDoc, $reflection]);
        $normalizer = new ObjectNormalizer($metadata, new MetadataAwareNameConverter($metadata), propertyTypeExtractor: $types);

        return new Serializer([
            new RateSetResponseDenormalizer($normalizer), new ArrayDenormalizer(), $normalizer,
        ], [new XmlEncoder()]);
    }
}
