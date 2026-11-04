#!/bin/sh
# make test — отдельный Compose project, сервисы и writable-копия приложения.
set -eu
cd "$(dirname "$0")/../.."

# Одинаковые UID/GID для образа и имени внешнего cache, включая запуск без Make.
export APP_UID="${APP_UID:-$(id -u)}"
export APP_GID="${APP_GID:-$(id -g)}"
export COMPOSE_PROJECT_NAME="cbrf-cource-test-$(id -u)-$$"
compose() { docker compose -p "$COMPOSE_PROJECT_NAME" -f compose.test.yaml "$@"; }
cleanup() {
    compose down --remove-orphans --rmi local >/dev/null || true
    docker image rm "$COMPOSE_PROJECT_NAME-app:test" >/dev/null 2>&1 || true
}
trap cleanup 0
trap 'exit 130' INT
trap 'exit 143' TERM

docker volume create "cbrf-cource-composer-${APP_UID:-1000}-${APP_GID:-1000}" >/dev/null
unit_only=0
previous=''
for argument in "$@"; do
    if [ "$argument" = --testsuite=Unit ] || { [ "$previous" = --testsuite ] && [ "$argument" = Unit ]; }; then
        unit_only=1
    fi
    previous="$argument"
done
if [ "$unit_only" = 1 ]; then
    compose build test
else
    compose build test cbr-stub
    compose up -d --wait --wait-timeout 120 postgres redis rabbitmq cbr-stub
fi
compose run --rm --no-deps test "$@"
