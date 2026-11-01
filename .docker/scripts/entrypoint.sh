#!/bin/sh
set -eu

# Единая часовая зона для системных утилит и всех дочерних PHP-процессов.
export TZ="${TZ:-Europe/Moscow}"

cd /app
rm -f /tmp/app-ready
mkdir -p var

# В dev app и worker используют общие vendor/var через bind mount.
# Блокировка снимается также при ошибке или остановке контейнера.
exec 9>var/.startup.lock
flock -x 9

if [ ! -f .env ]; then
    cp .env.dist .env
fi

if [ -z "${APP_SECRET:-}" ]; then
    APP_SECRET="$(sed -n 's/^APP_SECRET=//p' .env | tr -d "\"'\r")"
    if [ -z "$APP_SECRET" ]; then
        APP_SECRET="$(php -r 'echo bin2hex(random_bytes(32));')"
        sed -i '/^APP_SECRET=/d' .env
        printf '\nAPP_SECRET=%s\n' "$APP_SECRET" >> .env
    fi
    export APP_SECRET
fi

mode=dev
if [ "${APP_ENV:-prod}" = prod ]; then
    mode=prod
fi
fingerprint="$(sha256sum composer.json composer.lock)-${mode}"
if [ ! -f vendor/autoload.php ] || [ ! -f vendor/autoload_runtime.php ] || \
    [ "$(cat vendor/.startup-fingerprint 2>/dev/null || true)" != "$fingerprint" ]; then
    echo 'Installing Composer dependencies...'
    if [ "$mode" = prod ]; then
        composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --classmap-authoritative
    else
        composer install --prefer-dist --no-interaction --no-progress --no-scripts
    fi
    php bin/console cache:clear --no-interaction
    printf '%s\n' "$fingerprint" > vendor/.startup-fingerprint
fi

if [ "${APP_INITIALIZE:-0}" = 1 ]; then
    php bin/console cache:clear --no-interaction
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    touch /tmp/app-ready
fi

flock -u 9
exec 9>&-

# PHP получает сигналы остановки напрямую (в Compose — через init).
exec "$@"
