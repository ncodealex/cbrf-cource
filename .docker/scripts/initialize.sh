#!/bin/sh
set -eu
cd /app

php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# Worker и Dispatcher требуют готовности всех зависимостей, включая брокер.
export HEALTH_CHECK_RABBITMQ=1
exec php bin/console app:health:ready --no-interaction
