<?php

declare(strict_types=1);

namespace App\Cources\Domain;

use Symfony\Component\Clock\DatePoint;

/**
 * Календарные даты курсов ЦБ — даты по московскому времени. Дата представляется
 * DatePoint на полночь Europe/Moscow.
 */
final class MoscowCalendar
{
    public const string TIMEZONE = 'Europe/Moscow';

    public static function timezone(): \DateTimeZone
    {
        return new \DateTimeZone(self::TIMEZONE);
    }

    /**
     * @param string $format формат даты без времени, например 'Y-m-d' или 'd.m.Y'
     *
     * @throws \InvalidArgumentException дата не соответствует формату или не существует (31.02)
     */
    public static function date(string $value, string $format = 'Y-m-d'): DatePoint
    {
        try {
            $date = DatePoint::createFromFormat('!'.$format, $value, self::timezone());
        } catch (\DateMalformedStringException $e) {
            throw new \InvalidArgumentException(\sprintf('Некорректная дата "%s": ожидается формат %s', $value, $format), 0, $e);
        }

        // createFromFormat переносит переполнение (31.02 → 03.03): сверяем обратным форматированием.
        if ($date->format($format) !== $value) {
            throw new \InvalidArgumentException(\sprintf('Некорректная дата "%s": ожидается формат %s', $value, $format));
        }

        return $date;
    }

    /** Календарная дата момента времени по Москве. */
    public static function dateOf(\DateTimeInterface $moment): DatePoint
    {
        return self::date(\DateTimeImmutable::createFromInterface($moment)->setTimezone(self::timezone())->format('Y-m-d'));
    }
}
