#!/bin/sh
set -eu
cd /app
rm -f /tmp/app-initialized

php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# Worker и Dispatcher требуют готовности всех зависимостей, включая брокер.
export HEALTH_CHECK_RABBITMQ=1
php bin/console app:health:ready --no-interaction

# Compose оставляет контейнер для ручного exec только после успешной подготовки.
if [ "$#" -gt 0 ]; then
    touch /tmp/app-initialized
    exec "$@"
fi
