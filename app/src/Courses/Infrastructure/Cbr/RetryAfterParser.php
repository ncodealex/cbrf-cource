<?php

declare(strict_types=1);

namespace App\Courses\Infrastructure\Cbr;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\DatePoint;

final readonly class RetryAfterParser
{
    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function parse(?string $value): ?DatePoint
    {
        if (null === $value || '' === ($value = trim($value))) {
            return null;
        }
        try {
            if (preg_match('/\A[0-9]+\z/', $value)) {
                /*
                 * Проверка диапазона исключает переполнение при переводе
                 * значения в секунды.
                 */
                $seconds = filter_var(
                    ltrim($value, '0') ?: '0',
                    \FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 0]],
                );
                if (false === $seconds || $seconds > 253402300799 - $this->clock->now()->getTimestamp()) {
                    return null;
                }

                return DatePoint::createFromInterface($this->clock->now())->modify(\sprintf('+%d seconds', $seconds));
            }
            $value = preg_replace('/(?<= [A-Za-z]{3})  (?=[0-9] )/', ' ', $value) ?? $value;
            /*
             * Поддерживаются IMF-fixdate и два исторических формата HTTP/1.1.
             */
            foreach (['D, d M Y H:i:s \G\M\T', 'l, d-M-y H:i:s \G\M\T', 'D M j H:i:s Y'] as $format) {
                try {
                    $date = DatePoint::createFromFormat('!'.$format, $value, new \DateTimeZone('GMT'));
                } catch (\Exception) {
                    continue;
                }
                $errors = DatePoint::getLastErrors();
                if ((false === $errors || (0 === $errors['warning_count'] && 0 === $errors['error_count'])) && $date->format($format) === $value) {
                    return $date;
                }
            }
        } catch (\Exception) {
            return null;
        }

        return null;
    }
}
