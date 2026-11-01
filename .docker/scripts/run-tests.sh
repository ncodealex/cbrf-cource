#!/bin/sh
set -eu

# vendor/var принадлежат этому контейнеру; рабочий /source не меняется.
tar -C /source --exclude=./vendor --exclude=./var --exclude=./.phpunit.cache -cf - . | tar -C /app -xf -
cd /app
composer install --no-interaction --prefer-dist --no-progress
php bin/console doctrine:database:create --if-not-exists --no-interaction --env=test
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test
exec php vendor/bin/phpunit "$@"
