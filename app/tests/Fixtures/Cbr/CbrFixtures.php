<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Cbr;

/**
 * Ответы ЦБ в исходной кодировке (windows-1251). Числа условные:
 * совпадают с наборами из docs/exchange-rate-spec.md §4.5.
 */
final class CbrFixtures
{
    public static function load(string $name): string
    {
        $content = file_get_contents(__DIR__.'/'.$name);
        if (false === $content) {
            throw new \RuntimeException('Фикстура не найдена: '.$name);
        }

        return $content;
    }

    /**
     * Подставляет UTF-8 фрагмент XML в обёртку с декларацией windows-1251.
     */
    public static function windows1251(string $utf8Xml): string
    {
        $converted = iconv('UTF-8', 'WINDOWS-1251', '<?xml version="1.0" encoding="windows-1251"?>'."\n".$utf8Xml);
        if (false === $converted) {
            throw new \RuntimeException('Не удалось преобразовать фикстуру в windows-1251');
        }

        return $converted;
    }
}
