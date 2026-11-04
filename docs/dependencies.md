# Зависимости приложения

Состав проверяется по `app/composer.json`, зафиксированным версиям в `app/composer.lock`,
импортам PHP, конфигурации контейнера Symfony и тестам. Отсутствие прямого импорта
не означает, что пакет не нужен: часть компонентов вызывается через DI или Serializer.

## Рабочие зависимости

| Пакет | Использование |
|---|---|
| `doctrine/dbal` | PostgreSQL, параметризованный SQL, транзакции, блокировки, миграции и инфраструктурные пробы |
| `doctrine/doctrine-bundle` | Регистрация общего DBAL Connection и конфигурация подключения Symfony |
| `doctrine/doctrine-migrations-bundle` | Запуск существующих SQL-миграций при подготовке приложения и тестов |
| `symfony/framework-bundle` | Kernel, DI, конфигурация и интеграционные тесты |
| `symfony/console` | Команды получения, Refresh, импорта и Dispatcher |
| `symfony/clock` | DatePoint, внедряемые часы и MockClock в тестах |
| `symfony/dotenv` | Загрузка окружения через Symfony Runtime и bootstrap тестов |
| `symfony/runtime` | Entrypoint `bin/console` |
| `symfony/flex` | Composer auto-scripts и согласование установленных Symfony recipes |
| `symfony/http-client` | Scoped `cbr.client`, HTTP-моки, управление CBR Stub в E2E |
| `symfony/serializer` | XML → внешние DTO ЦБ; XmlEncoder, Denormalizer, ObjectNormalizer |
| `symfony/property-access` | ObjectNormalizer читает и заполняет поля DTO |
| `symfony/property-info` | Типы параметров конструктора и коллекций при десериализации ответа ЦБ |
| `phpdocumentor/reflection-docblock` | PhpDocExtractor для типизированного списка котировок; также используется тестовой фабрикой Serializer |
| `phpstan/phpdoc-parser` | Извлечение PHPDoc-типов в PropertyInfo; требуется и транзитивно reflection-docblock и Deptrac |
| `symfony/lock` | Redis load lock для дедубликации загрузки RateSet и проверки владения |
| `symfony/rate-limiter` | Общий лимитер обращений к ЦБ |
| `symfony/messenger` | Worker, обработка сообщений и подтверждения доставки |
| `symfony/amqp-messenger` | Существующие RabbitMQ-транспорты, включая `rates_import` |
| `symfony/monolog-bundle` | Регистрация Monolog, каналов и файловых обработчиков |

## Инструменты разработки

| Пакет | Использование |
|---|---|
| `symfony/process` | Subprocess в интеграционных тестах; транзитивная зависимость инструментов |
| `phpunit/phpunit` | Unit, Integration, E2E и конкурентные тесты |
| `phpstan/phpstan` | Статический анализ на уровне max |
| `phpstan/phpstan-symfony` | Типы сервисов из прогретого test-контейнера Symfony |
| `phpstan/phpstan-doctrine` | DBAL extensions и stubs; удаление ORM не делает этот пакет лишним |
| `phpstan/phpstan-phpunit` | Уточнение типов assertions, mocks и тестовых doubles |
| `phpstan/extension-installer` | Автоматическое подключение установленных расширений PHPStan |
| `friendsofphp/php-cs-fixer` | Единый стиль PHP и проверка `make qa` |
| `deptrac/deptrac` | Границы Cources, Import, Support и Shared |

## Удалённые зависимости

- `doctrine/orm`: нет EntityManager, ORM-сущностей, DQL, ORM QueryBuilder или ORM Mapping.
  Удалены ORM-конфигурация, ORM cache pools и разрешения ORM/Collections в Domain.
- `symfony/web-profiler-bundle`, `symfony/twig-bundle`, `symfony/twig-bridge`, `twig/twig`:
  приложение не рендерит шаблоны; Twig устанавливался для WebProfiler.
  Удалены регистрация бандлов, конфигурация профайлера и его маршруты.
- `symfony/debug-bundle`: dump/dd и его интеграция не используются. Xdebug остаётся
  в dev-образе; базовая обработка исключений Symfony остаётся доступной.
- `symfony/scheduler`: импорт планирует собственный Dispatcher; Schedule/ScheduleProvider отсутствуют.
- `symfony/validator`: нет ограничений Validator и вызовов ValidatorInterface;
  входные данные и инварианты проверяются существующими Parser, VO и Domain.
- `doctrine/collections`, `doctrine/inflector`, `doctrine/instantiator`, `doctrine/lexer`,
  `symfony/translation-contracts`, `symfony/polyfill-php86`: больше не требуются оставшемуся дереву зависимостей.
- Прямой `require-dev` для `symfony/stopwatch` удалён, но сам пакет остаётся:
  он требуется Doctrine Migrations в рабочем окружении и CS Fixer при разработке.
- Неиспользуемое разрешение Composer-плагина `php-http/discovery` удалено.

Версии оставшихся библиотек сохраняются при удалении пакетов через минимальное
обновление lock-файла. Обнаруженный `composer audit` дефект PHPUnit 13.4.3 исправляется
отдельным минимальным обновлением до 13.4.4.

## PHP и системные пакеты

- `ext-amqp`, `ext-redis`, `ext-pdo_pgsql` нужны рабочим адаптерам RabbitMQ, Redis и PostgreSQL.
- `ext-bcmath` нужен точным расчётам через `BcMath\Number`; float его не заменяет.
- `ext-dom` нужен XML Encoder; `ext-iconv` — ответам ЦБ в windows-1251 и XML fixtures.
- `ext-ctype` нужен компонентам Symfony. OPcache остаётся для рабочего образа,
  PCNTL — для обработки сигналов Worker, Dispatcher и Progress Observer.
- `ext-intl` и ICU сохранены после проверки чистой сборки без расширения: FrameworkBundle
  выдаёт deprecation о производительности при его отсутствии. Прямых вызовов Intl в приложении
  нет, но удаление переводит Symfony на polyfill и добавляет сообщения при загрузке Kernel.
- `libpq` и `rabbitmq-c` нужны соответствующим PHP-расширениям.
  Composer используется при запуске контейнера, поэтому `git` и `unzip` сохранены
  для установки пакетов и fallback загрузки исходников.
- Компиляторы и dev-библиотеки устанавливаются временно и удаляются в том же слое Docker.

Изменение зависимостей проверяется установкой lock-файла, прогревом контейнера,
`make qa`, полным `make test` на изолированных PostgreSQL, Redis, RabbitMQ и CBR Stub,
а также отдельной установкой без require-dev и прогревом production-контейнера.

## Результат проверки состава

После очистки lock-файл содержит 116 пакетов вместо 130: 62 рабочих вместо 71
и 54 пакета разработки вместо 59. Из сохранённых версий изменён только PHPUnit:
13.4.3 → 13.4.4; остальные версии не обновлялись.

- `make qa`: CS Fixer, PHPStan max и Deptrac проходят; нарушений и skip_violations нет.
- `make test a="--debug"`: 406 тестов проходят без ошибок и пропусков
  (342 Unit, 5 Application, 59 Integration, включая 12 E2E и 5 конкурентных тестов).
- Production Docker target собирается; чистые `composer install --no-dev`,
  `composer check-platform-reqs --no-dev`, прогрев и `lint:container` проходят.
- Проверено отсутствие ORM, Twig и PHPUnit в production-установке.
- Финальный `composer audit --locked` не сообщает известных уязвимостей.

Один первоначальный полный прогон был остановлен после зависания без нового вывода
в E2E-части. На повторном полном запуске с диагностическим выводом зависание
не воспроизвелось. Тесты и бизнес-алгоритмы в рамках очистки зависимостей не изменялись.
