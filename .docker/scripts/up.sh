#!/bin/sh
# Подготовка приложения выполняется entrypoint внутри контейнера.
set -eu
cd "$(dirname "$0")/../.."
COMPOSE_PROD="docker compose -f compose.yaml"

printf '%s\n' "Сборка и запуск prod-окружения"
${COMPOSE_PROD} build app
${COMPOSE_PROD} up -d --wait --wait-timeout 600 --remove-orphans
