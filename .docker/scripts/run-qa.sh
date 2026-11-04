#!/bin/sh
set -eu
cd /app
if [ "${1:-}" = fix ]; then
    exec vendor/bin/php-cs-fixer fix
fi
php bin/console cache:warmup --env=dev >/dev/null
php bin/console cache:warmup --env=test >/dev/null
vendor/bin/php-cs-fixer check --diff
vendor/bin/phpstan analyse --no-progress --memory-limit=512M
exec vendor/bin/deptrac analyse --no-progress --cache-file=var/.deptrac.cache
