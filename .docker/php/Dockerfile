ARG DOCKER_REGISTRY=docker.io/library
ARG PHP_VERSION=8.4.27
ARG DEBIAN_VERSION=trixie
ARG COMPOSER_VERSION=2.10.3

FROM ${DOCKER_REGISTRY}/composer:${COMPOSER_VERSION} AS composer

# Debian 13: обычная поддержка до августа 2028, LTS до июня 2030.
FROM ${DOCKER_REGISTRY}/php:${PHP_VERSION}-cli-${DEBIAN_VERSION} AS base

ARG PHPREDIS_VERSION=6.3.0
ARG AMQP_VERSION=2.2.0
ENV TZ=Europe/Moscow

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends tzdata libicu76 libpq5 librabbitmq4 \
        $PHPIZE_DEPS libicu-dev libpq-dev librabbitmq-dev; \
    docker-php-ext-install -j"$(nproc)" bcmath intl pcntl pdo_pgsql; \
    pecl install "https://pecl.php.net/get/redis-${PHPREDIS_VERSION}.tgz" "https://pecl.php.net/get/amqp-${AMQP_VERSION}.tgz"; \
    docker-php-ext-enable redis amqp; \
    apt-mark auto $PHPIZE_DEPS libicu-dev libpq-dev librabbitmq-dev; \
    apt-get autoremove -y --purge; \
    rm -rf /var/lib/apt/lists/* /tmp/pear /root/.pearrc

COPY .docker/php/conf.d/php.ini /usr/local/etc/php/conf.d/zz-app.ini
WORKDIR /app
ENTRYPOINT ["app-entrypoint"]
CMD ["php", "bin/console", "list"]

FROM base AS tooling
RUN apt-get update && apt-get install -y --no-install-recommends git unzip util-linux \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/bin/composer

# Установка production-зависимостей кешируется отдельно от исходников приложения.
FROM tooling AS prod-dependencies
COPY app/composer.json app/composer.lock app/symfony.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

FROM tooling AS dev
ARG XDEBUG_VERSION=3.5.3
ARG APP_UID=1000
ARG APP_GID=1000
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS; \
    pecl install "https://pecl.php.net/get/xdebug-${XDEBUG_VERSION}.tgz"; \
    docker-php-ext-enable xdebug; \
    apt-mark auto $PHPIZE_DEPS; \
    apt-get autoremove -y --purge; \
    rm -rf /var/lib/apt/lists/* /tmp/pear /root/.pearrc
RUN set -eux; \
    test "${APP_UID}" != 0; \
    group="$(getent group "${APP_GID}" | cut -d: -f1 || true)"; \
    if [ -z "$group" ]; then groupadd -g "$APP_GID" app; group=app; fi; \
    user="$(getent passwd "${APP_UID}" | cut -d: -f1 || true)"; \
    if [ -n "$user" ]; then userdel "$user"; fi; \
    useradd -m -u "$APP_UID" -g "$group" -s /bin/sh app; \
    mkdir -p /app/var/log /home/app/.composer; \
    chown -R app:"$group" /app /home/app
COPY .docker/php/conf.d/dev.ini /usr/local/etc/php/conf.d/zz-dev.ini
COPY --chmod=755 .docker/scripts/dev-entrypoint.sh /usr/local/bin/app-entrypoint
COPY --chmod=755 .docker/scripts/initialize.sh /usr/local/bin/app-initialize
COPY --chmod=755 .docker/scripts/run-qa.sh /usr/local/bin/app-qa
COPY --chmod=755 .docker/scripts/run-tests.sh /usr/local/bin/app-test
COPY --chmod=755 .docker/scripts/phpunit.sh /usr/local/bin/app-phpunit
ENV COMPOSER_HOME=/home/app/.composer
USER app

FROM base AS prod
RUN groupadd -g 10001 app && useradd -m -u 10001 -g app -s /usr/sbin/nologin app
COPY --chown=app:app --from=prod-dependencies /app/vendor /app/vendor
COPY --chown=app:app app/ /app/
COPY --chmod=755 .docker/scripts/entrypoint.sh /usr/local/bin/app-entrypoint
COPY --chmod=755 .docker/scripts/initialize.sh /usr/local/bin/app-initialize
RUN mkdir -p /app/var/log && chown -R app:app /app
ENV APP_ENV=prod APP_DEBUG=0
USER app
RUN --mount=from=composer,source=/usr/bin/composer,target=/usr/bin/composer \
    php /usr/bin/composer dump-autoload --no-dev --classmap-authoritative --no-scripts --no-interaction

# Общий HTTP Stub: готовые зависимости, без Composer, Xdebug и расширений приложения.
FROM prod-dependencies AS stub-dependencies
COPY app/src/ /app/src/
RUN composer dump-autoload --no-dev --no-scripts --no-interaction

FROM ${DOCKER_REGISTRY}/php:${PHP_VERSION}-cli-${DEBIAN_VERSION} AS cbr-stub
WORKDIR /app
COPY --from=stub-dependencies /app/vendor /app/vendor
COPY --from=stub-dependencies /app/src /app/src
USER www-data
ENTRYPOINT ["php", "-S", "0.0.0.0:8080", "/app/tests/Fixtures/Cbr/stub.php"]
