# Поддержка инфраструктуры

Проверено 04.11.2026 по официальным политикам проектов. Patch-теги закреплены
для воспроизводимости. Их нужно обновлять внутри выбранной поддерживаемой ветки:
длительный срок ветки не означает безопасность устаревшего patch-релиза.

| Компонент | Выбранная ветка | Политика сопровождения |
|---|---|---|
| Symfony | 7.4 | LTS приложения |
| PostgreSQL | 18 (образ 18.6) | Пять лет поддержки major-ветки, до 14.11.2030; отдельного тега LTS нет |
| Redis Open Source | 8.2 (образ 8.2.9) | Extended release, до 01.09.2030 |
| PHP | 8.4 (образ 8.4.27) | Security support до 31.12.2028; PHP не обозначает отдельные community-ветки LTS |
| Debian | 13 trixie в PHP-образе | Полная поддержка до 09.08.2028, LTS до 30.06.2030 |
| Debian | 12 bookworm в официальном Redis 8.2.9 | LTS до 30.06.2028; ОС образа нужно обновить раньше окончания поддержки Redis |

Источники:

- [PostgreSQL Versioning Policy](https://www.postgresql.org/support/versioning/)
- [Redis Open Source version management](https://redis.io/docs/latest/operate/oss_and_stack/install/version-mgmt/)
- [PHP Supported Versions](https://www.php.net/supported-versions.php)
- [Debian 13 Release Information](https://www.debian.org/releases/trixie/)
- [Debian LTS](https://wiki.debian.org/LTS)

## RabbitMQ: различать LTS и бесплатную поддержку

[Официальная таблица RabbitMQ](https://www.rabbitmq.com/release-information)
разделяет community support и commercial support. Ветка 4.2 обозначена LTS
и имеет коммерческое сопровождение до 30.06.2030, но её community support
закончилось 31.07.2026. Просто установить Docker Hub-образ 4.2 недостаточно,
чтобы получить коммерческие обновления и поддержку.

Выбрана ветка RabbitMQ 4.4 с community-поддержкой (`4.4-management-alpine`).
Она не является LTS; minor-тег получает patch-обновления внутри ветки.

Nginx/Traefik не входят в текущий Compose: приложение запускает CLI и Messenger.
PECL-расширения и Composer — инструменты/клиенты, у них нет того же LTS-цикла,
что у серверных продуктов. Их версии закреплены, совместимость проверяется сборкой и тестами.

## Существующие данные

Нельзя использовать смену Docker-тега как процедуру downgrade.

- PostgreSQL остаётся на major 18, его volume сохраняется.
- Redis переводится с 8.10 на 8.2 в новый `redis_data_v82`; прежний `redis_data`
  сохраняется и не открывается старым Redis. Кеш/лимитер начинают с пустого состояния,
  подтверждённые котировки и Import Jobs остаются в PostgreSQL.
- При смене major/minor RabbitMQ проверять поддерживаемый путь обновления;
  не открывать прежнюю metadata store более старым сервером.
- Перед сменой Redis на существующей установке остановить app, Worker и Dispatcher,
  чтобы старые процессы не продолжали исполнение после потери Redis lease.
- Не применять `down --volumes` к рабочему окружению ради обновления образов.

## Проверка окружения

Из корня репозитория:

```sh
make test
make qa
make test-startup
```

`test` запускает PHPUnit с изолированными PostgreSQL, Redis, RabbitMQ и CBR Stub.
`qa` проверяет стиль, PHPStan и границы модулей Deptrac.
`test-startup` проверяет холодный production-запуск, миграции, однократные миграции,
три Worker, TZ, общий путь журналов для run/exec и restart/recreate.
Обёртки удаляют ресурсы своего прогона и теги собранных образов; общий внешний
Composer cache и кеш сборки сохраняются.

Начальная миграция рассчитана на чистую БД. Применённую старую версию с тем же
номером она не обновляет; перед обновлением старой установки нужно завершить
прежнюю цепочку миграций. Readiness сверяет номера миграций, а не структуру таблиц.
