<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use Symfony\Component\Clock\DatePoint;

final class DatePointParser
{
    public const string TIMEZONE = 'Europe/Moscow';

    /**
     * Календарная дата момента времени на полночь по Москве.
     */
    public static function dateOf(\DateTimeInterface $moment): DatePoint
    {
        return self::parse(
            \DateTimeImmutable::createFromInterface($moment)
                ->setTimezone(self::timezone())
                ->format(DateFormatInterface::ISO),
        );
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function parse(string $value): DatePoint
    {
        if (preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $value, $parts)) {
            [, $year, $month, $day] = $parts;
        } elseif (preg_match('/\A([0-9]{1,2})([.\/-])([0-9]{1,2})\2([0-9]{4})\z/', $value, $parts)) {
            [, $day, , $month, $year] = $parts;
        } else {
            throw new \InvalidArgumentException('Некорректная календарная дата.');
        }

        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            throw new \InvalidArgumentException('Некорректная календарная дата.');
        }

        return DatePoint::createFromFormat(
            '!'.DateFormatInterface::ISO,
            \sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day),
            self::timezone(),
        );
    }

    public static function timezone(): \DateTimeZone
    {
        return new \DateTimeZone(self::TIMEZONE);
    }
}
