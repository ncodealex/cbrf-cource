#!/bin/sh
# Подготовка приложения выполняется entrypoint внутри контейнера.
set -eu
cd "$(dirname "$0")/../.."
COMPOSE_DEV="docker compose -f compose.yaml -f compose.dev.yaml"

mkdir -p runtime/logs

printf '%s\n' "Сборка и запуск dev-окружения"
${COMPOSE_DEV} build app
${COMPOSE_DEV} up -d --wait --wait-timeout 600 --remove-orphans
