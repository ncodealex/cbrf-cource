#!/bin/sh
# Изолированная проверка production-образа и жизненного цикла Compose.
set -eu
cd "$(dirname "$0")/../.."
startup_project="cbrf-startup-test-$(id -u)-$$"
export COMPOSE_PROJECT_NAME="$startup_project"
export REDIS_URL=redis://redis:6379
export APP_ENV=prod APP_DEBUG=0 TZ=Europe/Moscow
export POSTGRES_HOST=postgres POSTGRES_PORT=5432 POSTGRES_DB=app POSTGRES_USER=test POSTGRES_PASSWORD=startup-test
export MESSENGER_TRANSPORT_DSN='amqp://rabbitmq:5672/%2f' RABBITMQ_USER=test RABBITMQ_PASSWORD=startup-test
export RABBITMQ_MANAGEMENT_PORT=0 CBR_BASE_URL=http://unused.invalid
# Подготовка обязана проверить брокер даже при отключённой ручной AMQP-пробе.
export HEALTH_CHECK_RABBITMQ=0
compose() { docker compose -p "$startup_project" -f compose.yaml "$@"; }
cleanup() {
    compose down --volumes --remove-orphans >/dev/null || true
    docker image rm "$startup_project-app:prod" >/dev/null 2>&1 || true
}
trap cleanup 0
trap 'exit 130' INT
trap 'exit 143' TERM

compose build app

assert_startup_failure() {
    if compose up -d --wait --wait-timeout 120 --scale worker=3; then
        echo 'Ошибка readiness должна блокировать запуск исполнителей.' >&2
        exit 1
    fi
    failed_app_id="$(compose ps -aq app)"
    test "$(docker inspect -f '{{.State.Status}}:{{.State.ExitCode}}' "$failed_app_id")" = exited:1
    compose logs --no-color app | grep -F "$1: failed"
    compose logs --no-color app | grep -F 'migrations: ok'
    for container_id in $(compose ps -q postgres redis rabbitmq); do
        test "$(docker inspect -f '{{.State.Health.Status}}' "$container_id")" = healthy
    done
    for container_id in $(compose ps -aq worker import-dispatcher); do
        test "$(docker inspect -f '{{.State.StartedAt}}' "$container_id")" = '0001-01-01T00:00:00Z'
    done
    compose rm -f app worker import-dispatcher
}

# Сервисы здоровы, миграции выполнены, но подключения приложения неверны.
REDIS_URL=redis://redis:1 assert_startup_failure redis
MESSENGER_TRANSPORT_DSN='amqp://rabbitmq:1/%2f' assert_startup_failure rabbitmq

compose up -d --wait --wait-timeout 120 --scale worker=3
app_id="$(compose ps -aq app)"
test "$(docker inspect -f '{{.State.Status}}:{{.State.ExitCode}}' "$app_id")" = running:0
for probe in postgres redis migrations rabbitmq; do
    compose logs --no-color app | grep -F "$probe: ok"
done
verify_workers() {
    test "$(compose ps -q worker | wc -l | tr -d ' ')" = 3
    for container_id in $(compose ps -q worker import-dispatcher); do
        docker exec "$container_id" php -r 'require "vendor/autoload.php"; exit(date_default_timezone_get() === "Europe/Moscow" && !class_exists("PHPUnit\\Framework\\TestCase") && !class_exists("Symfony\\Component\\Process\\Process") && !is_file("/usr/bin/composer") ? 0 : 1);'
        docker exec "$container_id" php bin/console app:health:ready --quiet
    done
}
verify_workers
compose exec -T app php bin/console doctrine:migrations:up-to-date --no-interaction
compose exec -T app php bin/console lint:container --env=prod --no-debug
compose run --rm --no-deps -e TZ=Asia/Tokyo app php -r 'exit(date_default_timezone_get() === "Asia/Tokyo" ? 0 : 1);'
if compose run --rm --no-deps -e POSTGRES_DB=missing_startup_test app app-initialize >/dev/null 2>&1; then
    echo 'Миграция должна завершиться ошибкой для отсутствующей БД.' >&2
    exit 1
fi
# Запись через основной процесс и docker exec должна идти в один каталог.
if compose run --rm --no-deps app php bin/console startup_logging_probe >/dev/null 2>&1; then exit 1; fi
worker_id="$(compose ps -q worker | head -n 1)"
if docker exec "$worker_id" php bin/console startup_logging_probe >/dev/null 2>&1; then exit 1; fi
compose run --rm --no-deps app sh -c 'test "$(grep -h -c startup_logging_probe var/log/error-*.log | awk "{s+=\$1} END {print s}")" = 2'
compose restart app
compose up -d --no-deps --wait --wait-timeout 120 app
compose exec -T app php bin/console app:health:ready --quiet
compose restart worker import-dispatcher
verify_workers
compose up -d --no-deps --force-recreate --wait --wait-timeout 120 --scale worker=3 worker import-dispatcher
verify_workers
printf '%s\n' 'Startup checks passed: migrations, readiness gate, workers, shared log path, timezone, restart and recreate.'
