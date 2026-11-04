#!/bin/sh
set -eu
export TZ="${TZ:-Europe/Moscow}"
export APP_ENV="${APP_ENV:-prod}"
cd /app
if [ ! -f vendor/autoload_runtime.php ]; then
    echo 'В образе отсутствуют зависимости приложения. Пересоберите образ.' >&2
    exit 1
fi
if [ ! -f .env ]; then cp .env.dist .env; fi
# Symfony создаёт локальный кеш при запуске команды; отдельный запуск ядра не нужен.
exec "$@"
