#!/bin/sh
set -eu
# Исходники только для чтения; vendor, cache и logs принадлежат тестовому контейнеру.
# Архив сначала создаётся целиком: ошибка чтения не теряется в POSIX pipeline.
archive="$(mktemp)"
trap 'rm -f "$archive"' 0
tar -C /source --exclude=./vendor --exclude=./var --exclude=./.phpunit.cache \
    --exclude=./.env --exclude='./.env.*' --exclude=./phpunit.xml \
    --exclude=./.php-cs-fixer.php --exclude=./.php-cs-fixer.cache --exclude=./.deptrac.cache \
    -cf "$archive" .
tar -C /app -xf "$archive"
cp /source/.env.dist /app/.env
rm -f "$archive"
trap - 0
export APP_ENV=test
exec app-entrypoint app-phpunit "$@"
