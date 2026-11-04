# AGENTS.md — проект курсов Банка России

Этот файл задаёт правила работы во всём репозитории. Основное приложение находится в `app/`, namespace — `App\`. При расхождениях старой документации с кодом проверяй реализацию, конфигурацию, миграции и тесты. Не считай запланированные механизмы реализованными.

## Стек и рабочее окружение

- PHP >= 8.4.27, Symfony 7.4; точные версии зависимостей — в `app/composer.lock`.
- PostgreSQL, Redis, RabbitMQ; Doctrine DBAL/ORM и Migrations. Наличие ORM не означает, что модели курсов хранятся через ORM.
- Во всех новых PHP-файлах — `declare(strict_types=1);`, типизированные параметры и возвращаемые значения.
- Конфигурация Symfony — PHP в `app/config/`. YAML используется для Compose и Deptrac; запрет на YAML относится к конфигурации Symfony.
- PHPStan — level `max`, PHP CS Fixer, Deptrac; настройки находятся в `app/`.
- `.docker/` содержит Dockerfile и скрипты окружения. Корневой Makefile — основной интерфейс команд. Приложение внутри контейнера находится в `/app`, Compose-сервис называется `app`.

## Архитектура и размещение кода

```text
app/src/
├── Cources/                       # бизнес-модуль курсов; сохранять это написание
│   ├── Domain/                    # модели, VO, расчёты, доменные ошибки
│   ├── Application/
│   │   ├── UseCase/<Scenario>/     # прикладной сценарий и его вход/результат
│   │   ├── Contract/              # порты хранения, кеша и внешнего источника
│   │   ├── Service/               # общая оркестрация сценариев
│   │   ├── Factory/
│   │   └── Exception/
│   ├── Infrastructure/            # реализации портов, преобразование данных
│   │   ├── Cbr/                   # HTTP, Serializer, Response, Mapper, ошибки ЦБ
│   │   ├── Cache/
│   │   ├── Persistence/
│   │   └── Mapping/
│   └── Endpoints/Console/         # команды и форматирование вывода
├── Import/                        # асинхронное планирование и исполнение импорта
│   ├── Domain/                    # Entity/ImportJob, Enum, ValueObject, Exception
│   ├── Application/               # Contract, DTO, Configuration, Message, Factory, UseCase
│   ├── Infrastructure/            # Persistence (DBAL), Messenger
│   └── Endpoints/                 # Console и Messenger
├── Support/
│   ├── Health/                    # liveness/readiness и инфраструктурные пробы
│   └── RateLimiter/               # ограничение частоты запросов
└── Shared/
    ├── Contracts/                # публичные межмодульные API Rates и Import
    └── Domain/                   # общие даты, форматы и базовые исключения
```

### UseCase — единая реализация сценария

- Новый прикладной сценарий размещай в `Application/UseCase/<Scenario>/`. Обработчик — `<Scenario>Handler` с методом `__invoke()`.
- Входные данные и результат выделяй в типы рядом с обработчиком по необходимости: пример — `GetExchangeRateQuery` и `GetExchangeRateResult`. Не вводи DTO ради обёртки одного аргумента: `RefreshRateSetHandler` сейчас принимает `DatePoint` и возвращает `RateSet`.
- HTTP, Console и будущие message handlers должны вызывать одну реализацию сценария. Не копируй получение данных, расчёт, сохранение или обновление в точки входа.
- Общую прикладную оркестрацию оставляй в Application-сервисах: `RateSetResolver` реализует `RateSetProviderInterface` и используется сценариями. Доменные вычисления остаются в Domain.
- UseCase не зависит от Console, HTTP, формата JSON и конкретного клиента ЦБ, Redis или DBAL. Зависимости внешних операций внедряй через контракты.

### Endpoints — вход в приложение

- Для всех модулей используй название `Endpoints`, включая Support. Новые каталоги `Presentation` не создавать.
- Точка входа разбирает и проверяет транспортные параметры, формирует вход сценария, вызывает UseCase, преобразует результат и ошибки в ответ/exit code.
- Форматирование относится к Endpoints: пример — `Cources/Endpoints/Console/GetExchangeRate/Response/JsonResponse`. Application Result не должен импортировать классы Endpoints.
- Не обращаться из Endpoints напрямую к БД, Redis или внешнему API. Для новых зависимостей на ошибки предпочитай контракт уровня Application/Domain, а не конкретной инфраструктуры.

### Support и Shared

| Размещение | Ответственность |
|---|---|
| `Support/Health/Application` | Контракт `HealthProbe`, оркестратор `ReadinessChecker`, результат `ReadinessReport` |
| `Support/Health/Infrastructure` | Пробы PostgreSQL, Redis, RabbitMQ и запуск ограниченных по времени процессов |
| `Support/RateLimiter/Application` | Контракт `RequestRateLimiter` и результат `RateLimitDecision` |
| `Support/RateLimiter/Infrastructure` | Реализация через Symfony RateLimiter |
| `Shared/Domain` | `DatePointParser`, `DateFormatInterface`, общие базовые исключения |

- Support содержит самостоятельные технические возможности с Application-контрактами и Infrastructure-реализациями. Domain и Endpoints добавляются по необходимости, пустые слои не создавать.
- Бизнес-домен Cources не зависит от Support. Прикладной/инфраструктурный код использует публичные Application-контракты Support, а не его реализации. Support не должен знать конкретные реализации Cources.
- Shared не зависит от Cources, Import или Support. Shared/Contracts содержит только согласованные межмодульные API и нейтральные ошибки; внутренние контракты хранения остаются в своих модулях.
- Не создавать каталоги `Utils`, `Helpers`, `Common`: используй конкретные роли `Parser`, `Mapper`, `Factory`, `Policy`, `Calculator` в модуле-владельце.
- Клиент ЦБ принадлежит `Cources/Infrastructure/Cbr`. Не переносить его в Support или отдельный Integrations только по аналогии с другим проектом.

### Зависимости слоёв

Основное направление: `Endpoints → Application → Domain`; Infrastructure реализует контракты Application и использует Domain. Domain не знает об Application, Infrastructure или Endpoints.

| Слой | Допустимые зависимости |
|---|---|
| Domain | Собственные доменные типы, Shared.Domain; разрешённые Deptrac API DatePoint, Doctrine Mapping/Collections |
| Application | Domain, Shared.Domain, Shared.Contracts, Application-контракты Support; DatePoint, ClockInterface, DI-атрибуты Symfony, PSR |
| Infrastructure | Domain, Application, Shared, Application-контракты Support, внешние библиотеки |
| Endpoints | Application, Domain, общие типы и транспортные API Symfony; без конкретных Infrastructure-реализаций |

Точные автоматические ограничения — в `app/deptrac.yaml`. Deptrac отдельно контролирует Cources и Import, включая четыре слоя каждого модуля и Shared.Contracts. Не расширяй разрешения Vendor и baseline ради обхода нарушения.

## Правила реализации

- Следуй существующим immutable-моделям и `final readonly` там, где это соответствует назначению класса. Не навязывай моделям курсов приватные конструкторы, getter traits, AggregateRoot или ORM-атрибуты: текущие модели используют публичные конструкторы и свойства.
- Инварианты и расчёты — в Domain. Документируй смысл, ограничения и побочные эффекты нетривиальных доменных операций, не пересказывай сигнатуры.
- Контракты курсов — в `Cources/Application/Contract`: Repository, Cache, Provider, External Gateway и Date Policy. Репозиторий возвращает доменные объекты, не транспортные Response.
- Сервисы регистрируются через autowire/autoconfigure. Предпочитай `#[AsAlias]`, `#[Autowire]`, `#[AutowireIterator]` и специализированные атрибуты; не дублируй настройки в PHP-конфиге.
- `app/config/services.php` оставляй для resource/exclude, параметров, фабрик и overrides. При переносе DTO проверяй исключения из регистрации сервисов.
- Новые сообщения ошибок приложения пиши по-русски; машинные имена классов и коды могут быть английскими. Сохраняй исходное исключение в `previous`, когда оборачиваешь ошибку.

### Читаемость PHP, контрактов и SQL

- Не используй однострочные PHPDoc-комментарии, включая комментарии только с `@return`, `@param` или кратким описанием. Оформляй PHPDoc в несколько строк и описывай назначение, поведение, ограничения и смысл возвращаемого значения там, где они не очевидны из сигнатуры. Не ограничивай документацию интерфейса только типом вроде `list<DatePoint>`: из контракта должно быть понятно, какие именно даты возвращаются и при каких условиях.
- В интерфейсах размещай полное описание поведения метода, возвращаемого результата, побочных эффектов и ожидаемых исключений через `@throws`. Если поведение контракта изменилось, одновременно актуализируй PHPDoc интерфейса; в частности, не оставляй устаревшим описание `RateSetRepositoryInterface`.
- В реализациях интерфейсов не дублируй документацию контракта. Используй многострочный PHPDoc с `@inheritdoc`; отдельное описание добавляй только для существенных особенностей реализации, которых нет в контракте.
- Не используй ассоциативные массивы как неявные структуры данных для результатов, статистики и передачи нескольких связанных значений между слоями. Предпочитай именованный типизированный DTO с явными свойствами и возвращай его из контракта, репозитория и UseCase. Не передавай такой массив позиционным аргументом в другой DTO или Result: зависимость должна быть предсказуемой и читаемой по типам.
- DTO и другие классы, которые лежат внутри autowire-ресурса, но не являются сервисами, исключай из регистрации атрибутом `#[Exclude]`. Не добавляй ради одного такого класса новый путь в `exclude` файла `app/config/services.php`.
- Конструкторы всегда форматируй многострочно: открывающая скобка параметров, каждый promoted property или обычный параметр и закрывающая скобка располагаются на отдельных строках. Это правило действует и для конструктора с единственным параметром. Сохраняй завершающую запятую в многострочном списке параметров.
- SQL-запросы записывай через nowdoc `<<<'SQL' ... SQL`, а не однострочными строковыми литералами. Форматируй запрос по смысловым блокам: отдельные строки для `SELECT`, `FROM`, `JOIN`, `WHERE`, CTE, условий и группировки; используй отступы так, чтобы структура сложного запроса читалась без мысленного разбора строки. Не вкладывай вызов DBAL с SQL непосредственно в другой вызов: сначала сохрани результат запроса в именованную переменную, затем преобразуй или верни его.

## Даты и время

- Во всех слоях приложения используй `Symfony\Component\Clock\DatePoint` как тип даты и времени, пакет `symfony/clock` — для работы с часами.
- Текущее время получай через внедрённый `ClockInterface`. Domain получает время аргументом, а не читает часы сам. Не используй статический `Clock::get()` в прикладной логике; в тестах управляй временем через `Symfony\Component\Clock\MockClock`.
- Форматы календарных дат определены в `App\Shared\Domain\DateFormatInterface`: для отображения пользователю (UI, включая консоль) — `DateFormatInterface::UI` (`d.m.Y`), для внутреннего строкового представления — `DateFormatInterface::ISO` (`Y-m-d`). Используй константы вместо повторения строковых литералов.
- Эти константы описывают дату без времени суток; не используй их для сериализации полного момента времени с потерей часов, минут и секунд. Форматы внешних протоколов, например `d/m/Y` для ЦБ, остаются в инфраструктурных адаптерах.
- Календарь приложения — `Europe/Moscow`. Для разбора календарных дат используй `DatePointParser::parse()`, для преобразования текущего момента в календарную дату по Москве — `DatePointParser::dateOf()`.

## Особенности курсов и хранения

### Даты и расчёт

- `requestedDate` — календарная дата запроса, `effectiveDate` — дата действия из ответа ЦБ. Несколько календарных дат могут ссылаться на один набор; не вычисляй дату действия по календарю выходных.
- Политика `CbrRateSetDatePolicy` ограничивает обычные запросы диапазоном от `1992-07-03` до текущей даты; проверка выполняется до чтения кеша/БД в Resolver.
- Денежные значения — `BcMath\Number`, во внешних JSON/хранилище — decimal strings. Не использовать `float`; сохранять исходный номинал, scale и завершающие нули. Числовой код валюты сохранять строкой с ведущими нулями.
- RateSet не хранит рубль среди котировок: `get()` формирует рублёвую котировку отдельно. Пустые наборы, дубли валют и включённый рубль отвергаются моделью.
- `ExchangeRateCalculator` считает курс одной единицы и кросс-курс; промежуточная точность — `INTERNAL_SCALE = 30`. Округление результата выполняется в `GetExchangeRateResult`, а не внутри расчёта.
- GetExchangeRate получает предыдущий набор запросом на день перед текущей `effectiveDate`, а не перед исходной `requestedDate`.

### Получение, сохранение и refresh

1. `RateSetResolver`: проверка даты → Redis → PostgreSQL → ЦБ при отсутствии записи в БД.
2. После ответа ЦБ сначала сохраняется БД, затем заполняется Redis. Все источники возвращают RateSet.
3. Отсутствие данных — `RateSetNotFound`; сбой кеша — `RateSetCacheFailure`. Resolver логирует сбой кеша и продолжает работу. Ошибки БД, конфликты и ошибки источника не превращаются в cache miss.

- `rate_sets`: один набор на `effective_date`, котировки в JSONB. `rate_request_dates`: точное соответствие `requested_date → effective_date`. Не подбирать ближайшую дату SQL-запросом.
- `DoctrineRateSetRepository` использует DBAL. Эти таблицы обслуживаются миграциями и исключены из ORM SchemaTool через schema_filter.
- `save()` атомарно сохраняет набор и календарную связь. Повтор идентичных данных допустим; изменение содержимого или подтверждённой связи вызывает `RateSetConflict`. Сохраняй поведение конкурентных INSERT и последующего отдельного SELECT при READ COMMITTED.
- Redis хранит полный набор под `rateset:date:YYYY-MM-DD` с TTL из `CACHE_DEFAULT_TTL`. Общий `RateSetMapper` используется для хранения; он не заменяет маппер внешнего ответа ЦБ.
- Refresh проверяет дату, получает все связанные даты, удаляет их кеш (либо ключ исходной даты, если связей нет), удаляет набор через репозиторий и повторно вызывает Provider для запрошенной даты.
- Миграция `Version20261101203000.php` устанавливает `ON DELETE CASCADE`: удаление общего набора удаляет все его календарные связи. Refresh не восстанавливает заранее каждую из них и не является общей транзакцией Redis + БД + HTTP. Не описывай его как атомарную замену.

### Граница интеграции ЦБ

- `CbrRateSetGateway` использует scoped `cbr.client`, endpoint `/scripts/XML_daily.asp`, параметр `date_req` в формате `d/m/Y`; `fetchLatest()` не передаёт дату.
- Поток преобразования: XML → Symfony Serializer/Denormalizer → `RateSetResponse`/`RateItemResponse` → `CbrRateSetResponseMapper` → Domain. DTO внешней системы не должны проникать в UseCase или Domain.
- Различай HTTP 429 (`CbrRateLimited`), транспортные/прочие HTTP-ошибки (`CbrUnavailable`), ошибки сериализации (`CbrSerialization`) и некорректные данные (`CbrInvalidResponse`). Сохраняй ограничения XML-парсинга и сетевые таймауты; не логируй полный ответ или credentials.
- CbrRateSetGateway вызывает общий RateLimiter. Resolver использует Redis load lock с повторной проверкой БД; не изменять настройки лимитера при рефакторинге импорта.
- Асинхронный импорт реализован в Import через PostgreSQL Jobs, Dispatcher и Messenger; транспорт и очередь — rates_import. RetryPolicy принадлежит Support/Retry. Наличие Scheduler само по себе не означает дополнительных фоновых сценариев.

## Команды и тестирование

Команды выполняются из корня репозитория:

```sh
make dev                              # dev-окружение
make shell                            # shell сервиса app
make test                             # все наборы в изолированном окружении
make test a="--testsuite Unit"
make test a="--testsuite Application"
make test a="--testsuite Integration"
make qa                               # CS Fixer check, PHPStan, Deptrac
make cs-fix                           # изменяет форматирование PHP
```

- Unit: доменные инварианты и расчёты, сценарии с подменами зависимостей, мапперы и ошибки адаптеров.
- Application: HTTP через Symfony Kernel, сейчас health endpoints.
- Integration: реальные PostgreSQL, Redis и RabbitMQ; хранение, конкурентные записи, очистка и таймауты.
- `make test` создаёт отдельный Compose project; не направляй интеграционные тесты на рабочую БД или рабочие Redis-ключи. Для ЦБ используй подмену HTTP и fixtures в `app/tests/Fixtures/Cbr`.
- При изменении расчёта проверяй номиналы, кросс-курсы, рубль, предыдущий набор и точность. При изменении хранения — cache miss/failure, идемпотентность, конфликты, связанные даты и каскадное удаление. При изменении адаптера — некорректные данные и отказы внешней системы.
- Выбирай проверки по изменённому поведению. Для правки только документации достаточно проверки фактов, ссылок и diff; PHP-тесты не обязательны.
- Для ручного PHPStan нужен прогретый test-контейнер Symfony; `make qa` выполняет прогрев самостоятельно. Не заявляй об успешных проверках, если они не запускались.

## Навигация и известные расхождения

| Задача | Источник |
|---|---|
| Прикладные сценарии | `app/src/Cources/Application/UseCase/` |
| Получение из кеша/БД/ЦБ | `app/src/Cources/Application/Service/RateSetResolver.php` |
| Расчёт курса | `app/src/Cources/Domain/ExchangeRateCalculator.php` |
| Адаптер ЦБ | `app/src/Cources/Infrastructure/Cbr/` |
| Хранение и refresh | `app/src/Cources/Infrastructure/Persistence/DoctrineRateSetRepository.php`, `app/migrations/` |
| Проверки поведения | `app/tests/Unit/Cources/`, `app/tests/Integration/Cources/` |
| Дополнительное описание хранения | `docs/rateset-storage.md` — сверять с текущим кодом |

Текущие расхождения не являются образцом для нового кода:

- Health и его unit-тест пока используют каталог `Presentation`; целевое название — Endpoints. В Deptrac слой также называется Presentation и собирает оба варианта каталогов.
- README и комментарии конфигурации местами описывают старый шаблон с `Modules/` и отдельной Integration. Реальный бизнес-модуль — Cources непосредственно в `app/src/`.
- В `services.php` остались exclude-пути GetExchangeRate DTO без сегмента `UseCase`. При отдельной задаче синхронизации DI их необходимо актуализировать.
- Console-команда GetExchangeRate пока импортирует инфраструктурное исключение ЦБ. Не распространять эту зависимость на новый код; исправление существующей границы требует отдельного изменения.
- Документация хранения местами описывает более строгую XML-проверку и прежний статус RateLimiter. Проверяй фактические Gateway/Serializer и подключение сервисов, прежде чем утверждать наличие защиты.

Не выполнять массовые переносы, переименование Cources или исправление этих расхождений попутно с другой задачей. Не переносить из чужих проектов правила legacy frontend, Bruno, Sync, getter traits или механизмы событий, которых здесь нет.

## Правила модуля Import и межмодульной интеграции

- Import не импортирует PHP-классы Cources; Cources не импортирует PHP-классы Import. Публичная точка взаимодействия — Shared/Contracts. Domain не использует DI, DBAL, Messenger или Monolog.
- RateImportStatus — единственный владелец матрицы действий: allows(), assertAllows(), allowedFor(). allowedFor() вычисляется через allows(); отдельные TransitionPolicy запрещены.
- ImportJob изменяет собственное состояние через claim, complete, defer, fail, recover, resetForRefresh, repairDone и retryError. RetryPolicy принимает решение о повторе в Application.
- ImportJobExecution — immutable fencing token успешного захвата (DatePoint, version, attempts). Recovery читает ImportJob, а не token исполнения. PostgreSQL повторно проверяет version при записи.
- ImportJobRepositoryInterface изменяет задания; ImportJobQueryRepositoryInterface читает снимки, ошибки и прогресс. Индивидуальные claim/finish/recover используют короткие транзакции с блокировкой строки и доменными операциями; массовые reset/retry остаются bulk SQL. Диспетчер сохраняет advisory lock и FOR UPDATE SKIP LOCKED.
- RateSetImportGateway реализован в Cources: успешный ensureAvailable подтверждает дату в PostgreSQL и обходит Redis-only результат. Необязательная executionVersion включает ImportExecutionFence: HTTP выполняется вне транзакции, сохранение проверяет Processing/version под блокировкой строки Job.
- ImportJobResetter и хранилище Refresh используют один DBAL Connection и одну транзакцию. Refresh увеличивает версии перед удалением RateSet. StaleImportExecution прерывает устаревшего Worker без подтверждения новой даты.
- Явная техническая SQL-граница: Import Persistence пока читает rate_request_dates для согласованных statistics/progress, проверки Complete и атомарного repairDone. Эти запросы намеренно сохранены в PostgreSQL; не заменять их независимыми чтениями с ослаблением конкурентных гарантий. В PHP межмодульных зависимостей нет.
- Старый FQCN сообщения поддерживает временный class_alias в Import/Infrastructure/Messenger/legacy-message.php, подключённый Composer files. Не удалять совместимость до освобождения старых очередей и failed transport. Новые сообщения используют Import/Application/Message, прежнюю очередь rates_import.
- Корреляционные идентификаторы принадлежат Support/Logging/Application/IncidentId, не Import. XML и файловые каналы логирования сохраняются.
- Unit-тесты импорта находятся в tests/Unit/Import, PostgreSQL/E2E — tests/Integration/Import, subprocess гонок — tests/Fixtures/Import. Для полного прогона обязательны изолированные PostgreSQL, Redis, RabbitMQ и CBR Stub.
