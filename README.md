# Symfony Backend Starter

## 1. Описание

Инфраструктурный шаблон backend-приложения на Symfony без бизнес-логики: Docker-окружение для разработки
и production-like запуска, PostgreSQL, Redis, RabbitMQ, Messenger worker, health checks, тесты и инструменты
качества кода. Используется как фундамент для новых проектов — бизнес-модули добавляются в `app/src/Modules/`.

## 2. Технологический стек

| Компонент | Версия |
|---|---|
| PHP (CLI, Alpine) | 8.4.27 — расширения pdo_pgsql, bcmath, intl, opcache, pcntl, redis 6.3.0, amqp 2.2.0 |
| Symfony | 7.4 LTS (Framework, Messenger + AMQP, Cache, HttpClient, Validator, Serializer, Monolog) |
| Doctrine | ORM 3.7, DBAL 4.5, Migrations 3.9 (DoctrineBundle 3.3) |
| PostgreSQL | 18.6 |
| Redis | 8.10.2 |
| RabbitMQ | 4.3.6 + Management UI |
| Nginx | 1.30.6 |
| Traefik (только dev) | v3.6.4 |
| Composer | 2.10.3 |
| Тесты и качество | PHPUnit 13, PHPStan 2 (level max), PHP CS Fixer 3, Deptrac 4, Xdebug 3.5 (dev), Symfony Debug/Web Profiler (dev) |

## 3. Архитектура проекта

```text
.docker/        Dockerfile'ы, конфигурация PHP/PHP-FPM/Nginx, скрипты make
app/            Symfony-приложение (конфигурация только на PHP: app/config/*.php)
runtime/        локальные временные файлы (в git не попадают)
compose.yaml       production-like окружение
compose.dev.yaml   development overlay (bind mount, Xdebug)
compose.test.yaml  отдельные тестовые сервисы без опубликованных портов
```

- **Образы.** `.docker/php/Dockerfile`: `base` (PHP CLI, Composer и расширения) → `dev`
  (Xdebug, пользователь с UID/GID хоста) и `prod` (код приложения, пользователь `app` UID 10001).
  Entry point устанавливает зависимости при отсутствии `vendor` или изменении Composer-манифестов,
  в prod — без dev-пакетов, с authoritative classmap. Затем прогревает кеш.
  Для установки при первом запуске нужен доступ к репозиториям Composer; `vendor` и `var` должны быть доступны для записи.
- **Сервисы.** `app` — контейнер для консольных команд (`docker compose exec app php bin/console ...`),
  `worker` — `messenger:consume async` (масштабируется `docker compose up -d --scale worker=N`),
  `postgres`, `redis`, `rabbitmq`. HTTP-сервер не запускается.
- **Данные** PostgreSQL, Redis и RabbitMQ хранятся в Docker Named Volumes `postgres_data`, `redis_data`,
  `rabbitmq_data`. Оба режима используют один Compose project, поэтому видят одни и те же данные;
  `make down` volumes не удаляет.
- **Слои приложения** (`app/src`): `Shared/` — общий код, `Support/` — технические модули (сейчас `Health`),
  `Modules/` — будущие бизнес-модули со структурой `Domain/ Application/ Infrastructure/ Presentation/`.
  Domain не зависит от фреймворка; Application — от Domain и абстракций; Infrastructure реализует контракты;
  Presentation — HTTP и Console. Правила проверяет Deptrac (`make qa`). Сервисы настраиваются атрибутами
  (`#[Route]`, `#[AutowireIterator]`, `#[Autowire]`, `#[AutoconfigureTag]` ...), в `services.php` — только
  resource/exclude, параметры и фабрики. Из фреймворка в Domain разрешены только атрибуты маппинга Doctrine ORM,
  Doctrine Collections и `DatePoint`; в Application — дополнительно DI-атрибуты Symfony и PSR-интерфейсы
  (`app/deptrac.yaml`).

## 4. Как поднять проект

```bash
make up
```

Собирает образы и запускает Compose. Файл `.env` необязателен. Внутри контейнера entrypoint
устанавливает зависимости, готовит кеш и в сервисе `app` применяет миграции. Worker запускается
после успешной подготовки `app`. При ошибке подготовки запуск завершается с ошибкой.

Entrypoint создаёт `/app/.env` из `/app/.env.dist`, если файла нет. Если `APP_SECRET`
не задан в окружении и файле, генерирует его и сохраняет в `/app/.env`.
Make не создаёт `.env` в корне репозитория.

```bash
docker compose exec app php bin/console list
docker compose run --rm app php bin/console list
```

## 5. Как разрабатывать

```bash
make dev     # development-окружение
make shell   # консоль контейнера app (composer, bin/console)
make logs    # логи (make logs s=worker)
make down    # остановка без удаления данных
make qa      # PHP CS Fixer, PHPStan, Deptrac; make cs-fix — исправить стиль
```

- `./app` подключён в контейнеры bind mount'ом: изменения PHP-кода видны на следующем запросе без пересборки
  (OPcache проверяет timestamp файлов). Worker — долгоживущий процесс: после изменения обработчиков
  перезапустите его `docker compose restart worker`.
- Зависимости Composer лежат в `app/vendor` на хосте; entrypoint выполняет `composer install`, если зависимости отсутствуют или Composer-манифесты изменились. Блокировка предотвращает одновременную установку из app и worker.
- PHP-процессы dev-контейнеров работают от пользователя `app`, UID/GID которого совпадают с пользователем
  хоста (Make передаёт `APP_UID`/`APP_GID` из `id -u`/`id -g`; их можно переопределить переменными окружения или аргументами Make). Файлы, созданные Composer,
  Symfony Console или тестами, принадлежат вам — `sudo chown` не нужен. После изменения UID/GID выполните
  `make dev`: образ пересоберётся.
- Xdebug: `XDEBUG_MODE=debug` в `.env` и `make dev`; IDE слушает порт 9003, server name — `starter`
  (маппинг `app/` → `/app`). Отладка запускается триггером (`XDEBUG_SESSION` cookie/параметр).
- Symfony Profiler: http://localhost:8080/_profiler (или http://app.localhost/_profiler). Ссылка на профиль
  конкретного API-запроса приходит в заголовке ответа `X-Debug-Token-Link`.

## 6. Как протестировать

```bash
make test
```

Каждый `make test` создаёт уникальный Compose project с собственными PostgreSQL, Redis и RabbitMQ.
У сервисов нет host ports; данные находятся в tmpfs. Исходники монтируются read-only и копируются
в тестовый контейнер; его `vendor`, cache и logs не затрагивают рабочее приложение.
Перед тестами создаётся тестовая БД и применяются миграции. После прогона/ошибки удаляются
только контейнеры и сеть этого тестового project. Существующие volumes не удаляются;
между прогонами сохраняется только отдельный cache архивов Composer для соответствующего UID/GID.

Наборы: `Unit` (моки и стабы), `Application` (HTTP через ядро Symfony), `Integration`
(реальные подключения, transport setup, bounded probes). Один набор: `make test a="--testsuite Unit"`.
Тестовые пароли намеренно содержат пробелы и URI-спецсимволы. Regression tests воспроизводят
зависшие AMQP/Redis подключения и SQL после успешного PostgreSQL handshake без остановки рабочих сервисов.

## 7. Таймауты и остановка

| Операция | Бюджет по умолчанию |
|---|---|
| Readiness probe одной зависимости, включая SQL/DNS/handshake | 2s wall-clock |
| Полная readiness: PostgreSQL + Redis + опциональный RabbitMQ | до 7s с завершением дочерних процессов |
| HTTP-клиент startup readiness / общий startup deadline | 8s / 60s |
| Redis connect/read | 1s / 1s |
| AMQP connect/read/write/RPC/publisher confirm | по 1s |
| AMQP heartbeat | 15s; не заменяет I/O timeouts |
| HttpClient inactivity / max duration | 5s / 20s |
| PHP execution / FPM request termination | 30s CPU / 40s wall-clock |
| Nginx FastCGI connect / send/read | 3s / 45s |
| FPM graceful stop | 50s |
| Nginx worker shutdown / container stop | 50s / 60s |
| Messenger handler budget / container stop | до 60s / 65s |

Readiness запускает минимальные дочерние PHP-процессы без повторной загрузки Symfony Kernel.
Credentials передаются через stdin, вывод драйверов отключён; зависший процесс принудительно
завершается. RabbitMQ проверяется штатным разбором DSN Messenger и открытием channel:
TLS, credentials и vhost проверяются без объявления очередей и без чтения сообщений.
Это добавляет запуск PHP-процесса только health-запросам, а не бизнес-endpoints.

Оба Messenger транспорта используют publisher confirms. Неуспешное подтверждение — ошибка
публикации; дубли после неоднозначного сетевого отказа остаются возможны, handlers должны быть
идемпотентными. `--time-limit=3600` ограничивает время жизни worker и проверяется между сообщениями,
он не прерывает handler. 60s — контракт для handlers: их внешние операции должны иметь собственные
deadlines; для длительных задач нужно согласованно увеличить stop grace period. При SIGTERM worker
завершает текущее сообщение в пределах grace period, затем Docker может применить SIGKILL.

## 8. Production performance

Production устанавливает зависимости с `--no-dev --classmap-authoritative` при запуске контейнера.
Кеш Symfony готовится после установки, когда доступны переменные окружения контейнера.
PHP CLI работает от пользователя `app`; OPcache CLI по умолчанию выключен.
После изменения кода нужно пересобрать образ и пересоздать контейнеры app/worker.

## Хранение курсов ЦБ

Получение через Redis → PostgreSQL → ЦБ, формат данных, правила конфликтов и тестирование описаны в [документации RateSet](docs/rateset-storage.md).
