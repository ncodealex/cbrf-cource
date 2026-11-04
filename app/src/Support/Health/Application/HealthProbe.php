<?php

declare(strict_types=1);

namespace App\Support\Health\Application;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Проверка одной инфраструктурной зависимости для readiness.
 *
 * Реализации регистрируются автоматически по тегу и должны выполняться быстро
 * (короткие таймауты, без тяжёлых операций и внешних API).
 */
#[AutoconfigureTag(self::TAG)]
interface HealthProbe
{
    public const string TAG = 'app.health_probe';

    /**
     * Имя проверки в ответе readiness, например "postgres".
     */
    public function name(): string;

    /**
     * Отключённая проверка не выполняется и не попадает в ответ.
     */
    public function isEnabled(): bool;

    /**
     * Выполнить ограниченную по времени проверку доступности зависимости.
     * Успешное завершение означает готовность зависимости в момент проверки.
     *
     * @throws \Throwable если зависимость недоступна
     */
    public function check(): void;
}
