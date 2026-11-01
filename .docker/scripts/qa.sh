#!/bin/sh
# make qa — PHP CS Fixer (dry-run), PHPStan, Deptrac. make cs-fix — исправить стиль.
set -eu
cd "$(dirname "$0")/../.."
COMPOSE_DEV="docker compose -f compose.yaml -f compose.dev.yaml"

${COMPOSE_DEV} build app

if [ "${1:-}" = "fix" ]; then
    exec ${COMPOSE_DEV} run --rm --no-deps -e XDEBUG_MODE=off -e APP_INITIALIZE=0 app vendor/bin/php-cs-fixer fix
fi

${COMPOSE_DEV} run --rm --no-deps -e XDEBUG_MODE=off -e APP_INITIALIZE=0 app sh -c '
    set -e
    php bin/console cache:warmup --env=dev >/dev/null
    php bin/console cache:warmup --env=test >/dev/null
    vendor/bin/php-cs-fixer check --diff
    vendor/bin/phpstan analyse --no-progress --memory-limit=512M
    vendor/bin/deptrac analyse --no-progress --cache-file=var/.deptrac.cache
'
