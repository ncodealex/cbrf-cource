#!/bin/sh
set -eu
export TZ="${TZ:-Europe/Moscow}"
export APP_ENV="${APP_ENV:-dev}"
cd /app
mkdir -p var
# Dev-процессы разделяют vendor и кеш в bind mount.
exec 9>var/prepare.lock
flock -x 9
if [ ! -f .env ]; then cp .env.dist .env; fi
fingerprint="$(sha256sum composer.json composer.lock)"
if [ ! -f vendor/autoload_runtime.php ] || \
    [ "$(cat vendor/.startup-fingerprint 2>/dev/null || true)" != "$fingerprint" ]; then
    composer install --prefer-dist --no-interaction --no-progress --no-scripts
    printf '%s\n' "$fingerprint" > vendor/.startup-fingerprint
    php bin/console cache:clear --no-warmup --no-interaction
fi
# Не запускаем второе ядро ради прогрева перед каждой командой.
flock -u 9
exec 9>&-
exec "$@"
