#!/bin/sh
# make test — отдельный Compose project, сервисы и writable-копия приложения.
set -eu
cd "$(dirname "$0")/../.."


COMPOSE_TEST="docker compose -p conexus-test-$(id -u)-$$ -f compose.test.yaml"
cleanup() { ${COMPOSE_TEST} down --remove-orphans >/dev/null || true; }
trap cleanup 0
trap 'exit 130' INT
trap 'exit 143' TERM

${COMPOSE_TEST} build test
${COMPOSE_TEST} up -d --wait --wait-timeout 120 postgres redis rabbitmq cbr-stub
${COMPOSE_TEST} run --rm --no-deps test "$@"
