ARG DOCKER_REGISTRY=docker.io/library
ARG NGINX_VERSION=1.30.6

FROM ${DOCKER_REGISTRY}/nginx:${NGINX_VERSION}-alpine

COPY .docker/nginx/default.conf /etc/nginx/conf.d/default.conf
# Nginx нужен только public/ (front controller и статические файлы), PHP-код ему не нужен.
COPY app/public /app/public

HEALTHCHECK --interval=10s --timeout=3s --start-period=5s --retries=3 \
    CMD wget -qO- http://127.0.0.1/nginx-health >/dev/null || exit 1
