# Префикс registry позволяет использовать зеркало Docker Hub (например, корпоративное).
ARG DOCKER_REGISTRY=docker.io/library
ARG PHP_VERSION=8.4.27
ARG ALPINE_VERSION=3.24
ARG COMPOSER_VERSION=2.10.3

FROM ${DOCKER_REGISTRY}/composer:${COMPOSER_VERSION} AS composer

# -----------------------------------------------------------------------------
# base: PHP CLI + расширения + общая конфигурация. Общий для dev и prod.
# -----------------------------------------------------------------------------
FROM ${DOCKER_REGISTRY}/php:${PHP_VERSION}-cli-alpine${ALPINE_VERSION} AS base

ARG PHPREDIS_VERSION=6.3.0
ARG AMQP_VERSION=2.2.0
ENV TZ=Europe/Moscow

# Компиляторы и *-dev пакеты ставятся виртуальным пакетом и удаляются в том же слое,
# в образе остаются только runtime-библиотеки. Все расширения собираются в одном слое.
RUN set -eux; \
    apk add --no-cache icu-libs libpq git unzip util-linux rabbitmq-c; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libpq-dev linux-headers rabbitmq-c-dev; \
    docker-php-ext-install -j"$(nproc)" bcmath intl opcache pcntl pdo_pgsql; \
    pecl install "redis-${PHPREDIS_VERSION}" "amqp-${AMQP_VERSION}"; \
    docker-php-ext-enable redis amqp; \
    apk del --no-network .build-deps; \
    rm -rf /tmp/pear ~/.pearrc

COPY .docker/php/conf.d/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY --chmod=755 .docker/scripts/entrypoint.sh /usr/local/bin/app-entrypoint

WORKDIR /app
ENTRYPOINT ["app-entrypoint"]
# Контейнер для docker compose exec app php bin/console ...
CMD ["sleep", "infinity"]

# -----------------------------------------------------------------------------
# dev: Composer + Xdebug, пользователь app с UID/GID пользователя хоста.
# Исходники подключаются bind mount'ом (./app:/app).
# -----------------------------------------------------------------------------
FROM base AS dev

ARG XDEBUG_VERSION=3.5.3
ARG APP_UID=1000
ARG APP_GID=1000

RUN set -eux; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers; \
    pecl install "xdebug-${XDEBUG_VERSION}"; \
    docker-php-ext-enable xdebug; \
    apk del --no-network .build-deps; \
    rm -rf /tmp/pear ~/.pearrc

# UID/GID могут совпасть с уже существующими в образе (например, gid 82 www-data):
# группу переиспользуем, конфликтующего пользователя удаляем.
RUN set -eux; \
    test "${APP_UID}" != 0 || { echo "APP_UID=0 is not allowed" >&2; exit 1; }; \
    group="$(getent group "${APP_GID}" | cut -d: -f1 || true)"; \
    if [ -z "${group}" ]; then addgroup -g "${APP_GID}" app; group=app; fi; \
    user="$(getent passwd "${APP_UID}" | cut -d: -f1 || true)"; \
    if [ -n "${user}" ]; then deluser "${user}"; fi; \
    adduser -D -u "${APP_UID}" -G "${group}" -h /home/app -s /bin/sh app; \
    mkdir -p /home/app/.composer; \
    chown app:"${group}" /home/app/.composer; \
    chown app:"${group}" /app

COPY .docker/php/conf.d/dev.ini /usr/local/etc/php/conf.d/zz-dev.ini

ENV COMPOSER_HOME=/home/app/.composer
USER app

# -----------------------------------------------------------------------------
# prod: код в образе; зависимости и кеш готовятся entrypoint при запуске.
# -----------------------------------------------------------------------------
FROM base AS prod

RUN addgroup -g 10001 app && adduser -D -u 10001 -G app -h /home/app -s /sbin/nologin app
COPY .docker/php/conf.d/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY --chown=app:app app/ /app/
RUN mkdir -p /app/var /home/app/.composer && chown -R app:app /app /home/app/.composer

ENV APP_ENV=prod APP_DEBUG=0 COMPOSER_HOME=/home/app/.composer
USER app
