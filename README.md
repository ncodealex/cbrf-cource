# Курсы Банка России

Symfony CLI-приложение для получения, хранения и расчёта валютных курсов,
а также асинхронного импорта через PostgreSQL Jobs и RabbitMQ.
`Cources` отвечает за курсы, `Import` — за задания; межмодульные API находятся
в `Shared/Contracts`. HTTP-сервера и HTTP-маршрутов нет.

## Запуск

Нужны Docker и Docker Compose 2.24.4 или новее (dev-конфигурация использует
`!override`). PHP, Composer и Make на хосте не требуются.

```sh
docker compose up --build -d --wait --wait-timeout 600
docker compose run --rm --no-deps app php bin/console app:rates:get USD --date=2026-10-24
docker compose run --rm --no-deps app php bin/console app:rates:import --days=30 --yes --progress
docker compose run --rm --no-deps app php bin/console app:rates:errors
```

`app` применяет миграции, выполняет `app:health:ready` и завершается с кодом 0.
На этом шаге проверка RabbitMQ включена обязательно. Worker и Dispatcher
ждут успешного завершения обоих шагов. Постоянно работают только эти процессы
и PostgreSQL, Redis, RabbitMQ. Ошибка миграции или readiness блокирует первый
запуск зависимых процессов.

```sh
docker compose up -d --wait --scale worker=3
docker compose logs --tail=100 worker import-dispatcher
docker compose run --rm --no-deps app php bin/console app:health:ready
docker compose down
```

Readiness проверяет PostgreSQL, Redis, версии миграций и, при
`HEALTH_CHECK_RABBITMQ=1`, RabbitMQ. При подготовке `app` это значение задаётся
для команды явно; при ручном запуске действует настройка окружения.
Это проверка зависимостей перед стартом, а не heartbeat Worker.
Worker обрабатывает SIGTERM и периодически перезапускается
по лимиту времени/памяти. Эти лимиты не прерывают отдельный обработчик сообщения.
После SIGTERM Compose даёт процессу 65 секунд на завершение, затем может отправить SIGKILL.
Это окно остановки, а не гарантированный предел длительности обработки: HTTP-таймауты
не ограничивают ожидание SQL-запросов и блокировок БД.
Масштабируются исполнители; `app` масштабировать не нужно.
Окружение рассчитано на один Compose-хост, а не на Swarm или HA-кластер.

RabbitMQ Management: `http://localhost:15672`, локальные credentials `app` / `app`.
Параметры можно задать через окружение или корневой `.env` по образцу `.env.example`.
Credentials передаются отдельно от DSN. Значения по умолчанию для импорта,
TTL кеша и необязательной проверки RabbitMQ определены в PHP-конфигурации;
Compose только передаёт заданные переопределения.

## Development

```sh
docker compose -f compose.yaml -f compose.dev.yaml up --build -d --wait --wait-timeout 600
docker compose -f compose.yaml -f compose.dev.yaml run --rm --no-deps app sh
```

`./app` монтируется в `/app`: исходники, vendor, кеш и журналы доступны на хосте.
**Логи находятся в `app/var/log/`**, без подкаталогов контейнеров и переменных пути.
Файлы `main-YYYY-MM-DD.log`, `error-YYYY-MM-DD.log`, `cbrf-YYYY-MM-DD.log`
одинаковы для ручных команд, Worker и Dispatcher. Monolog ротирует журналы:
14 файлов main/cbrf и 30 файлов error. Entrypoint журналы не удаляет.
XML попадает только в специализированный cbrf-журнал; остальные каналы его очищают.
Часть событий также направляется в stderr; в dev его порог — warning.
В production тот же путь `/app/var/log` хранится в named volume `app_logs`.

Dev-пользователь по умолчанию имеет UID/GID 1000. На Linux при других
идентификаторах задайте `APP_UID`/`APP_GID` перед сборкой. Make передаёт текущие
`id -u`/`id -g`. Xdebug выключен, включается через `XDEBUG_MODE=debug`.
После изменения кода долгоживущего обработчика перезапустите Worker.

Production-образ содержит готовые зависимости, без Composer и Xdebug.
Entrypoint проверяет vendor, создаёт `.env` из `.env.dist` при отсутствии,
передаёт управление команде через `exec`. Symfony создаёт необходимый локальный
кеш при запуске самой команды, без отдельного запуска `cache:warmup` в entrypoint.
У production-контейнеров кеш независимый: подготовка `app` не прогревает Worker.
APP_SECRET берётся из окружения или локального значения в `.env.dist`;
генерации секрета и общего тома подготовки нет.
Сервис `app` запускает `app-initialize` — скрипт `.docker/scripts/initialize.sh`:
сначала миграции, затем readiness. Ошибка любой команды завершает подготовку.
Worker и Dispatcher ждут успешного завершения `app` и healthy-зависимостей.
Readiness проверяет подключения с настройками приложения, включая авторизацию
RabbitMQ; локальные healthcheck инфраструктуры эту проверку не заменяют.
Это проверка при инициализации, а не гарантия доступности на всё время работы.
Обычные CLI-команды не выполняют эту последовательность автоматически.
Dev-entrypoint дополнительно устанавливает зависимости при изменении
composer.json/lock. Файловая блокировка защищает общий vendor и кеш при
одновременном запуске dev-процессов. Общего production-состояния подготовки нет.

## Тесты и QA

```sh
.docker/scripts/test.sh
.docker/scripts/test.sh --testsuite Unit
.docker/scripts/test-startup.sh

docker compose -f compose.yaml -f compose.dev.yaml run --build --rm --no-deps qa
docker compose -f compose.yaml -f compose.dev.yaml run --build --rm --no-deps qa app-qa fix
```

Полный прогон создаёт уникальный Compose project с PostgreSQL, Redis, RabbitMQ
и CBR Stub. Данные находятся в tmpfs, порты не публикуются. Исходники копируются
из read-only mount в тестовый контейнер; рабочие vendor, кеш, журналы и dotenv
не используются. После прогона контейнеры и теги образов удаляются; остаются
кеш сборки и общий внешний кеш Composer.

При выборе только `--testsuite Unit` скрипт не собирает Stub, не запускает
БД/Redis/RabbitMQ и не выполняет миграции. Integration и полный набор используют
изолированную инфраструктуру. Обёртка возвращает exit code тестов.

Прямой запуск Compose для полного набора:

```sh
docker volume create "cbrf-cource-composer-${APP_UID:-1000}-${APP_GID:-1000}"
docker compose -p cbrf-cource-test -f compose.test.yaml up --build --abort-on-container-exit --exit-code-from test test
docker compose -p cbrf-cource-test -f compose.test.yaml down
```

`test-startup.sh` проверяет production-образ: миграции, блокировку запуска
исполнителей при ошибке подключения приложения к Redis/RabbitMQ, три Worker, отсутствие
Composer/dev-зависимостей, общий путь журналов для `run` и `exec`, часовой пояс,
restart/recreate. Он удаляет только ресурсы собственного тестового project.
QA выполняет CS Fixer check, PHPStan и Deptrac.

Make — необязательная обёртка: `make dev`, `make shell`, `make test`,
`make test a="--testsuite Unit"`, `make test-startup`, `make qa`, `make cs-fix`.

## Существующие данные

`docker compose down` сохраняет volumes. Не используйте `down --volumes` для
обновления рабочего окружения. Начальная миграция рассчитана на чистую БД:
она не достраивает старую частично обновлённую схему с уже записанным номером
миграции. Readiness сравнивает версии, а не фактическую структуру таблиц.
Redis 8.2 использует отдельный `redis_data_v82`; прежний volume Redis 8.10
не открывается старой версией и автоматически не удаляется.
