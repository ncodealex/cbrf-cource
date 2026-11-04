#!/bin/sh
set -eu
cd /app
unit_only=0
previous=''
for argument in "$@"; do
    if [ "$argument" = --testsuite=Unit ] || { [ "$previous" = --testsuite ] && [ "$argument" = Unit ]; }; then
        unit_only=1
    fi
    previous="$argument"
done
if [ "$unit_only" = 0 ]; then
    php bin/console doctrine:database:create --if-not-exists --no-interaction --env=test
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test
fi
exec php vendor/bin/phpunit "$@"
