# ExchangeRate — спецификация реализации

Документ переводит функциональную спецификацию «Currency Rates Service» на устройство текущего
репозитория (Symfony 7.4, PHP 8.4, PostgreSQL, Redis, RabbitMQ, Deptrac-слои). Здесь описано, **что и где** реализуется,
с примерами кода, SQL и ожидаемым выводом. Если между документами
есть расхождения, для реализации действует этот документ. Бизнес-требования — в функциональной спецификации.

Содержание:

1. [Решения по открытым вопросам](#1-решения-по-открытым-вопросам)
2. [Источник: API Банка России](#2-источник-api-банка-россии)
3. [Структура модуля](#3-структура-модуля)
4. [Доменная модель и точность](#4-доменная-модель-и-точность)
5. [Хранилище (PostgreSQL)](#5-хранилище-postgresql)
6. [Кэш (Redis)](#6-кэш-redis)
7. [Получение курса: use case](#7-получение-курса-use-case)
8. [Загрузка набора: FetchRatesForDate](#8-загрузка-набора-fetchratesfordate)
9. [Задания, брокер, приоритеты](#9-задания-брокер-приоритеты)
10. [Ограничение нагрузки и глобальная пауза](#10-ограничение-нагрузки-и-глобальная-пауза)
11. [Backfill](#11-backfill)
12. [Планировщик: polling, dispatch, reconciliation](#12-планировщик-polling-dispatch-reconciliation)
13. [Интерфейсы: Console и HTTP](#13-интерфейсы-console-и-http)
14. [Ошибки](#14-ошибки)
15. [Конфигурация](#15-конфигурация)
16. [Изменения инфраструктуры репозитория](#16-изменения-инфраструктуры-репозитория)
17. [Тесты](#17-тесты)
18. [Порядок реализации](#18-порядок-реализации)

---

## 1. Решения по открытым вопросам

Ответы на вопросы из раздела 23 функциональной спецификации. Закрепляются в README.

| Вопрос                             | Решение                                                                                                                                                                            |
|------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Предыдущий торговый день           | Предыдущий **отдельный** официальный набор: для набора с датой действия `E` это набор, который ЦБ возвращает на запрос `date_req = E − 1 день`. Рабочий календарь не используется. |
| Точность кросс-курса               | Внутренний scale деления — 30; `ExchangeRate` хранит неокруглённые значения (§4.5).                                                                                                |
| Ожидание интерактивного запроса    | 8 с (`EXCHANGE_RATE_INTERACTIVE_WAIT`). Если не уложились — ошибка `LOADING_IN_PROGRESS`, задание остаётся.                                                                        |
| Частота polling                    | Таблица из ФС §10 как значение по умолчанию в `config/packages/exchange_rate.php`. В субботу и воскресенье интервал умножается на 2.                                               |
| Хранение оригинальных ответов      | Бессрочно. Ответ с тем же содержимым (тот же endpoint, тот же `date_req`, тот же SHA-256) повторно не сохраняется. Каждое обращение фиксирует `fetch_attempt`.                     |
| Глубина перепроверки корректировок | Раз в сутки в 21:00 МСК перепроверяются последние 10 календарных дней (`EXCHANGE_RATE_RECHECK_DAYS`).                                                                              |
| HTTP API                           | В текущем каркасе nginx/php-fpm удалены. Этап 1 — консоль и воркеры, этого достаточно для ТЗ. Этап 2 — HTTP-контроллер в `Endpoints/Http` и возврат веб-сервера (§16.4).           |

---

## 2. Источник: API Банка России

Используется один endpoint:

```
GET https://www.cbr.ru/scripts/XML_daily.asp?date_req=24/10/2026
```

Ответ в кодировке `windows-1251`, десятичный разделитель — запятая:

```xml
<?xml version="1.0" encoding="windows-1251"?>
<ValCurs Date="24.10.2026" name="Foreign Currency Market">
  <Valute ID="R01235">
    <NumCode>840</NumCode><CharCode>USD</CharCode><Nominal>1</Nominal>
    <Name>Доллар США</Name><Value>81,4500</Value><VunitRate>81,45</VunitRate>
  </Valute>
  <Valute ID="R01270">
    <NumCode>356</NumCode><CharCode>INR</CharCode><Nominal>100</Nominal>
    <Name>Индийских рупий</Name><Value>96,8790</Value><VunitRate>0,968790</VunitRate>
  </Valute>
  ...
</ValCurs>
```

> Числа в примерах этого документа условные и нужны только для иллюстрации расчётов.

Ключевые свойства, на которых строится логика:

| Свойство                                                                               | Как используется                                                                                                     |
|----------------------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------|
| `ValCurs@Date` — **дата действия** набора, она может отличаться от `date_req`          | Запрос на понедельник 26.10 вернёт `Date="24.10.2026"`. Отсюда соответствие «дата запроса → дата действия» (§5.3).   |
| Запрос на дату позже последней публикации возвращает последний набор                   | Правило будущих дат (§7.3). Нельзя считать этот набор курсом на запрошенную дату.                                    |
| `date_req = E − 1` возвращает набор, действовавший до `E`                              | Так определяется предыдущий набор (§1).                                                                              |
| `Value` / `Nominal` — исходная котировка                                               | Хранится как есть. `VunitRate` округлён ЦБ, **не используется** для расчётов: его сохраняем в raw только для аудита. |
| `XML_valFull.asp?d=0` / `d=1` — справочник валют с ежедневной / ежемесячной котировкой | Разделение ошибок `UNKNOWN_CURRENCY` и `UNSUPPORTED_CURRENCY` (§4.6).                                                |

Требования к HTTP-клиенту (`Infrastructure/Cbr/HttpCbrGateway`):

- scoped client `cbr.client`: base_uri из `CBR_BASE_URL`, `User-Agent: cb-cources/1.0`, timeout 5 с, max_duration 15 с.
  Бюджет handler 60 с, поэтому повторы внутри одного вызова не делаются.
- `200` и валидный XML → успех. Сохраняется raw-ответ.
- `429` → `SourceRateLimited(retryAfter)`. Значение берётся из `Retry-After` (секунды или HTTP-date), если заголовка
  нет — 60 с.
- `5xx`, timeout, ошибка транспорта → `SourceUnavailable`.
- `200` с невалидным XML, без `ValCurs@Date` или с нечисловым `Value` → `SourceInvalidResponse`. Тело всё равно
  сохраняется в raw (`parse_status = 'failed'`).

---

## 3. Структура модуля

Каталог `Endpoints` уже создан, его нужно добавить в Deptrac как слой Presentation (§16.1).

```text
app/src/Modules/ExchangeRate/
├── Domain/                                  # без фреймворка; BcMath\Number — часть PHP
│   ├── CurrencyCode.php                     # VO: нормализация, RUR→RUB
│   ├── CurrencyPair.php
│   ├── Quote.php                            # котировка: nominal + value + scale
│   ├── RateSet.php                          # набор на дату действия (+ revision)
│   ├── RateCalculator.php                   # прямые/кросс-курсы, разница
│   ├── RateCalculator.php                   # расчёт курсов и разницы без округления
│   ├── ExchangeRate.php                     # результат: current/previous/diff
│   ├── DateResolution.php                   # requested_date → effective_date
│   ├── Job/
│   │   ├── FetchJob.php                     # агрегат задания (состояния, lease)
│   │   ├── JobStatus.php                    # enum pending|running|deferred|completed|failed
│   │   ├── JobPriority.php                  # enum interactive=100|background=10
│   │   └── DeferReason.php                  # enum source_paused|rate_limited|source_error|lease_expired|limiter_unavailable
│   └── Exception/
│       ├── ExchangeRateException.php        # базовый, несёт ErrorCode
│       └── ErrorCode.php                    # enum кодов ошибок (§14)
│
├── Application/
│   ├── GetExchangeRate/
│   │   ├── GetExchangeRateQuery.php         # currency, base, ?date
│   │   ├── GetExchangeRateHandler.php       # use case (Flow A/B/C/D)
│   │   └── ExchangeRateView.php             # DTO для вывода (строки)
│   ├── FetchRates/
│   │   ├── FetchRatesForDate.php            # общая загрузка: inline и в воркере
│   │   ├── FetchOutcome.php
│   │   └── RateSetNormalizer.php            # DailyRatesResponse → RateSet + content_hash
│   ├── Backfill/StartBackfill.php
│   ├── Polling/PollPublication.php + PollingSchedulePolicy.php
│   ├── Reconcile/ReconcileHistory.php
│   ├── Dispatch/DispatchReadyJobs.php
│   ├── Status/SyncStatusQuery.php           # наблюдаемость
│   ├── Message/                             # POPO-сообщения (маршрутизация — в messenger.php)
│   │   ├── FetchRatesMessage.php            # jobId
│   │   ├── PollPublicationMessage.php
│   │   ├── DispatchReadyJobsMessage.php
│   │   └── ReconcileHistoryMessage.php
│   └── Port/                                # контракты, реализуются в Infrastructure
│       ├── CbrGateway.php                   # fetchDaily(?DatePoint): DailyRatesResponse
│       ├── DailyRatesResponse.php           # rawBody, effectiveDate, quotes, fetchedAt
│       ├── CurrencyDirectory.php            # справочник ЦБ
│       ├── RawResponseStore.php
│       ├── RateSetRepository.php
│       ├── DateResolutionRepository.php
│       ├── FetchJobRepository.php
│       ├── RateCache.php
│       ├── JobDispatcher.php                # публикация FetchRatesMessage
│       ├── SourceRateLimiter.php            # acquire(JobPriority): Permit|WaitFor
│       ├── SourcePause.php                  # pausedUntil(), pauseUntil(DatePoint)
│       └── MoscowClock.php                  # today(), now() в Europe/Moscow
│
├── Infrastructure/
│   ├── Cbr/HttpCbrGateway.php, DailyXmlParser.php, CurrencyDirectoryXmlParser.php
│   ├── Persistence/                         # DBAL (нужны ON CONFLICT и SKIP LOCKED)
│   │   ├── DbalRawResponseStore.php
│   │   ├── DbalRateSetRepository.php
│   │   ├── DbalDateResolutionRepository.php
│   │   ├── DbalFetchJobRepository.php
│   │   └── DbalCurrencyDirectory.php        # + обновление справочника из ЦБ раз в сутки
│   ├── Cache/RedisRateCache.php             # PSR-6 pool cache.exchange_rate, fail-open
│   ├── RateLimit/RedisTokenBucketLimiter.php + token_bucket.lua
│   ├── RateLimit/RedisSourcePause.php
│   ├── Messenger/MessengerJobDispatcher.php
│   ├── Scheduler/ExchangeRateSchedule.php   # #[AsSchedule('exchange_rate')]
│   └── Clock/SystemMoscowClock.php
│
└── Endpoints/
    ├── Console/
    │   ├── GetExchangeRateCommand.php       # app:exchange-rate:get
    │   ├── BackfillCommand.php              # app:exchange-rate:backfill
    │   ├── SyncStatusCommand.php            # app:exchange-rate:status
    │   └── ErrorExitCode.php                # ErrorCode → exit code
    ├── Messenger/
    │   ├── FetchRatesMessageHandler.php     # #[AsMessageHandler]
    │   ├── PollPublicationMessageHandler.php
    │   ├── DispatchReadyJobsMessageHandler.php
    │   └── ReconcileHistoryMessageHandler.php
    └── Http/                                # этап 2
        ├── ExchangeRateController.php
        └── ProblemJsonExceptionListener.php
```

Принципы:

- `Application` не знает о Messenger, Redis, DBAL, HttpClient. Только о `Port/*`, PSR и DI-атрибутах.
- Message handlers с `#[AsMessageHandler]` — точки входа, поэтому лежат в `Endpoints/Messenger` и делегируют в
  Application. Атрибут относится к слою Vendor и в Application запрещён.
- Persistence на DBAL без ORM-сущностей. Схема явная, в миграциях. Upsert и захват заданий требуют SQL, который ORM не
  выражает.

---

## 4. Доменная модель и точность

### 4.1. Арифметика

Используется `BcMath\Number` (PHP 8.4, расширение `bcmath` уже установлено). `float` в модуле
запрещён для курсов. Проверка: правило PHPStan / ревью. Внутренний scale делений — `INTERNAL_SCALE = 30`.

### 4.2. CurrencyCode

```php
namespace App\Modules\ExchangeRate\Domain;

final readonly class CurrencyCode
{
    public const string RUB = 'RUB';

    private function __construct(public string $value) {}

    public static function fromString(string $raw): self
    {
        $code = strtoupper(trim($raw));
        if (1 !== preg_match('/^[A-Z]{3}$/', $code)) {
            throw ExchangeRateException::invalidArgument('currency', 'Код валюты — три латинские буквы (ISO 4217)');
        }

        return new self('RUR' === $code ? self::RUB : $code);
    }

    public function isRuble(): bool { return self::RUB === $this->value; }
    public function equals(self $o): bool { return $this->value === $o->value; }
}
```

| Ввод                  | Результат          |
|-----------------------|--------------------|
| `usd`, ` USD `        | `USD`              |
| `rur`, `RUR`, `rub`   | `RUB`              |
| `US`, `US1`, `доллар` | `INVALID_ARGUMENT` |

### 4.3. Quote: стоимость одной единицы

```php
final readonly class Quote
{
    public function __construct(
        public CurrencyCode $code,
        public string $cbrId,         // R01235
        public int $nominal,          // 1, 10, 100, 10000, 1000000
        public Number $value,         // ровно как в источнике: '96.8790' (scale 4)
        public string $name,
    ) {}

    /** Точная стоимость одной единицы в рублях. */
    public function unitRate(): Number
    {
        // Для номинала 10^k деление точно при scale = scale(value) + k.
        // Для номинала не степени 10 (в истории ЦБ не встречался) — INTERNAL_SCALE.
        $k = self::powerOfTen($this->nominal);

        return null !== $k
            ? $this->value->div($this->nominal, $this->value->scale + $k)
            : $this->value->div($this->nominal, RateCalculator::INTERNAL_SCALE);
    }
}
```

| Валюта | Nominal | Value     | `unitRate()` |
|--------|---------|-----------|--------------|
| USD    | 1       | `81.4500` | `81.4500`    |
| INR    | 100     | `96.8790` | `0.968790`   |
| IDR    | 10000   | `49.8765` | `0.00498765` |

Разбор строки ЦБ `"96,8790"` → `new Number('96.8790')`. Запятая заменяется точкой, больше никаких
преобразований нет. Хвостовые нули сохраняются: scale = 4.

### 4.4. RateCalculator

```php
final class RateCalculator
{
    public const int INTERNAL_SCALE = 30;

    /** Курс 1 единицы $pair->currency в $pair->base по одному набору. Без округления. */
    public function rate(RateSet $set, CurrencyPair $pair): Number
    {
        if ($pair->currency->equals($pair->base)) {
            return new Number('1');
        }
        $c = $this->rubPerUnit($set, $pair->currency);   // RUB → '1'
        $b = $this->rubPerUnit($set, $pair->base);

        return $pair->base->isRuble()
            ? $c                                          // X/RUB — точное значение
            : $c->div($b, self::INTERNAL_SCALE);          // кросс и RUB/X
    }

    public function exchangeRate(RateSet $current, RateSet $previous, CurrencyPair $pair): ExchangeRate
    {
        $cur  = $this->rate($current, $pair);
        $prev = $this->rate($previous, $pair);

        // Разница — по неокруглённым значениям (ФС §5.5).
        return new ExchangeRate($pair, $current->effectiveDate, $cur, $previous->effectiveDate, $prev, $cur->sub($prev));
    }
}
```

`rubPerUnit` бросает `RATE_NOT_FOUND_FOR_DATE`, если валюты нет в наборе (§4.6).

### 4.5. Результат ExchangeRate без округления

Правила:

1. `RateCalculator` вычисляет текущий и предыдущий курсы независимо, затем вычитает их без округления.
2. `ExchangeRate` хранит полученные `Number` как результат. Прямые курсы сохраняют масштаб опубликованной котировки;
   деление для кросс-курсов выполняется с 30 знаками после запятой.
3. Если конкретному интерфейсу нужно меньше знаков, форматирование выполняется при выводе.

Расчёт по условным наборам 24.10 (текущий) и 23.10 (предыдущий):

| Пара    | Внутреннее current (scale 30, сокращено) | Ответ current                      | Ответ previous                     | Ответ diff                          |
|---------|------------------------------------------|------------------------------------|------------------------------------|-------------------------------------|
| USD/RUB | `81.4500`                                | `81.4500`                          | `81.2000`                          | `0.2500`                            |
| INR/RUB | `0.968790`                               | `0.968790`                         | `0.965000`                         | `0.003790`                          |
| INR/USD | `0.011894290976058931…`                  | `0.011894290976058931860036832412` | `0.011884236453201970443349753694` | `0.000010054522856961416687078718`  |
| EUR/CNY | `8.241978984238178633…`                  | `8.241978984238178633975481611208` | `8.244736842105263157894736842105` | `-0.002757857867084523919255230897` |
| RUB/USD | `0.012277470841006752…`                  | `0.012277470841006752608962553713` | `0.012315270935960591133004926108` | `-0.000037800094953838524042372395` |

Исходные котировки: USD `81,4500`/`81,2000`, INR (100) `96,8790`/`96,5000`, EUR `94,1234`/`93,9900`,
CNY `11,4200`/`11,4000`.

Контрпример, который ловит тест: если округлить INR/USD до 4 знаков **до** вычитания, получится
`0.0119 − 0.0119 = 0.0000`. Реальное изменение `+0.0000100545…` пропадёт.

### 4.6. Различение «валюты нет»

Проверки идут по порядку. Первое сработавшее правило определяет ошибку:

| Условие                                                                      | Код                                                                                                    |
|------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------|
| Не `^[A-Z]{3}$` после нормализации                                           | `INVALID_ARGUMENT`                                                                                     |
| Нет ни в справочнике `d=0`, ни в `d=1`, ни в одном сохранённом наборе        | `UNKNOWN_CURRENCY`                                                                                     |
| Есть только в справочнике `d=1` (ежемесячные котировки)                      | `UNSUPPORTED_CURRENCY`                                                                                 |
| Набор на дату подтверждён, но валюты в нём нет (появилась позже / исключена) | `RATE_NOT_FOUND_FOR_DATE` (в `details`: `firstSeen`/`lastSeen` по таблице `rate_quote`, если известны) |
| Валюта есть в текущем наборе, но нет в предыдущем                            | `PREVIOUS_RATE_NOT_FOUND`                                                                              |

Историческая валюта (например, исключённая из котировок) не попадает в `UNKNOWN_CURRENCY`, если она есть
хотя бы в одном сохранённом наборе. Доступность определяется по набору на дату, а не по текущему справочнику.

---

## 5. Хранилище (PostgreSQL)

Одна миграция `migrations/Version2026xxxxExchangeRate.php`. DDL:

```sql
-- 7.1 Исходные ответы ЦБ: неизменяемы (только INSERT).
CREATE TABLE cbr_raw_response
(
    id             uuid PRIMARY KEY,
    endpoint       text        NOT NULL, -- 'XML_daily', 'XML_valFull'
    request_date   date        NULL,     -- date_req; NULL = последний набор
    body           bytea       NOT NULL, -- байты как получены (windows-1251)
    body_sha256    char(64)    NOT NULL,
    content_type   text        NULL,
    http_status    smallint    NOT NULL,
    fetched_at     timestamptz NOT NULL,
    parser_version smallint    NOT NULL, -- версия DailyXmlParser на момент разбора
    parse_status   text        NOT NULL CHECK (parse_status IN ('ok', 'failed'))
);
CREATE UNIQUE INDEX cbr_raw_response_dedup
    ON cbr_raw_response (endpoint, coalesce(request_date, '0001-01-01'), body_sha256);

-- Каждое обращение к ЦБ (в том числе с одинаковым ответом и с ошибкой). Используется для аудита и метрик.
CREATE TABLE cbr_fetch_attempt
(
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    job_id          uuid        NULL,
    endpoint        text        NOT NULL,
    request_date    date        NULL,
    priority        smallint    NOT NULL,
    started_at      timestamptz NOT NULL,
    duration_ms     integer     NOT NULL,
    outcome         text        NOT NULL, -- ok|rate_limited|unavailable|invalid
    http_status     smallint    NULL,
    raw_response_id uuid        NULL REFERENCES cbr_raw_response (id)
);

-- 7.2 Нормализованный набор: одна строка на дату действия.
CREATE TABLE rate_set
(
    effective_date          date PRIMARY KEY,
    current_revision        integer     NOT NULL,
    previous_effective_date date        NULL, -- подтверждено ответом ЦБ на E-1
    updated_at              timestamptz NOT NULL
);

-- Ревизии набора неизменяемы. Корректировка ЦБ создаёт новую ревизию (7.3).
CREATE TABLE rate_set_revision
(
    effective_date     date        NOT NULL REFERENCES rate_set (effective_date),
    revision           integer     NOT NULL,
    content_hash       char(64)    NOT NULL, -- sha256 нормализованных котировок
    normalized_version smallint    NOT NULL,
    raw_response_id    uuid        NOT NULL REFERENCES cbr_raw_response (id),
    source_fetched_at  timestamptz NOT NULL, -- защита от запоздалых исполнителей
    created_at         timestamptz NOT NULL,
    PRIMARY KEY (effective_date, revision),
    UNIQUE (effective_date, content_hash)
);

CREATE TABLE rate_quote
(
    effective_date date     NOT NULL,
    revision       integer  NOT NULL,
    char_code      char(3)  NOT NULL,
    cbr_id         text     NOT NULL,
    num_code       char(3)  NULL,
    nominal        integer  NOT NULL CHECK (nominal > 0),
    value          numeric  NOT NULL, -- без (p,s): PostgreSQL сохраняет scale ввода
    value_scale    smallint NOT NULL, -- явная исходная точность
    name           text     NOT NULL,
    PRIMARY KEY (effective_date, revision, char_code),
    FOREIGN KEY (effective_date, revision) REFERENCES rate_set_revision (effective_date, revision)
);
CREATE INDEX rate_quote_char_code ON rate_quote (char_code, effective_date);

-- Календарная дата → дата действия. Один набор на много календарных дат (выходные, праздники).
CREATE TABLE rate_date_resolution
(
    requested_date  date PRIMARY KEY,
    effective_date  date        NOT NULL REFERENCES rate_set (effective_date),
    source          text        NOT NULL CHECK (source IN ('direct', 'inferred')),
    raw_response_id uuid        NULL REFERENCES cbr_raw_response (id),
    confirmed_at    timestamptz NOT NULL
);

-- Справочник валют ЦБ.
CREATE TABLE cbr_currency
(
    cbr_id     text PRIMARY KEY,
    char_code  char(3)     NULL,
    frequency  text        NOT NULL CHECK (frequency IN ('daily', 'monthly')),
    name       text        NOT NULL,
    nominal    integer     NOT NULL,
    updated_at timestamptz NOT NULL
);

-- Задания загрузки (источник истины для очереди; RabbitMQ — только доставка).
CREATE TABLE fetch_job
(
    id               uuid PRIMARY KEY,
    request_date     date        NOT NULL,
    status           text        NOT NULL CHECK (status IN ('pending', 'running', 'deferred', 'completed', 'failed')),
    priority         smallint    NOT NULL, -- 100 interactive, 10 background
    force_refresh    boolean     NOT NULL DEFAULT false,
    attempts         smallint    NOT NULL DEFAULT 0,
    available_at     timestamptz NOT NULL,
    defer_reason     text        NULL,
    lease_token      uuid        NULL,
    lease_expires_at timestamptz NULL,
    dispatched_at    timestamptz NULL,
    last_error       text        NULL,
    origin           text        NOT NULL, -- interactive|backfill|poll|reconcile|follow_up
    created_at       timestamptz NOT NULL,
    updated_at       timestamptz NOT NULL,
    completed_at     timestamptz NULL
);
-- Не больше одного активного задания на дату. Завершённые остаются для истории.
CREATE UNIQUE INDEX fetch_job_active_date ON fetch_job (request_date)
    WHERE status IN ('pending', 'running', 'deferred');
CREATE INDEX fetch_job_ready ON fetch_job (priority DESC, available_at) WHERE status IN ('pending', 'deferred');
```

### 5.1. Почему `numeric` без scale

`'96.8790'::numeric` хранится и возвращается как `96.8790`. Хвостовые нули не теряются, копейки не
округляются. DBAL отдаёт `numeric` строкой, из неё сразу строится `new Number($row['value'])`.
`value_scale` дублирует scale явно (ФС §5.2: «однозначно восстанавливается»).

### 5.2. Content hash

`sha256` от канонической строки: котировки отсортированы по `char_code`, формат строки
`CHAR|cbr_id|num_code|name|nominal|value`, строки разделены `\n`, в конце добавлен `ValCurs@Date`. `name` входит в хэш,
чтобы его исправление создавало новую ревизию:
изменение названия не считается корректировкой курса. Тот же набор, полученный повторно, даёт тот же хэш,
поэтому дубля не будет.

### 5.3. Вывод соответствий дат (resolution inference)

Если ответ на `date_req = D` имеет `Date = E` (`E ≤ D`), то для любого `X ∈ [E, D]` действует набор `E`:
нового набора между `E` и `D` нет, иначе ЦБ вернул бы его. Поэтому одним ответом подтверждаются все даты интервала:

```
запрос 26.10 (пн) → Date=24.10
  rate_date_resolution: 26.10→24.10 (direct), 25.10→24.10 (inferred), 24.10→24.10 (inferred)
```

Активные задания на `25.10` и `24.10` в той же транзакции переводятся в `completed`. На 180 дней
выходит примерно 125 реальных обращений к ЦБ вместо 180.

Ограничение: для `D > today_msk` вывод выполняется, только если `E = D` (§7.3).

### 5.4. Сохранение набора (атомарно, идемпотентно)

Порядок внутри `FetchRatesForDate` после успешного HTTP:

1. **Отдельная транзакция:** `INSERT cbr_raw_response ... ON CONFLICT DO NOTHING RETURNING id`. Если строка уже была —
   `SELECT id`. Raw сохраняется даже при последующей ошибке разбора.
2. **Транзакция нормализации** (`READ COMMITTED` + `SELECT ... FOR UPDATE` на `rate_set`):
   ```sql
   INSERT INTO rate_set (effective_date, current_revision, updated_at) VALUES (:e, 0, now())
       ON CONFLICT DO NOTHING;
   SELECT current_revision FROM rate_set WHERE effective_date = :e FOR UPDATE;
   -- ревизия с тем же content_hash уже есть → новые строки не пишем
   -- иначе: INSERT rate_set_revision (revision = max+1) + INSERT rate_quote (все строки)
   UPDATE rate_set SET current_revision = :rev, updated_at = now()
    WHERE effective_date = :e
      AND (SELECT source_fetched_at FROM rate_set_revision
            WHERE effective_date = :e AND revision = current_revision) < :fetched_at  -- не перетираем более свежие данные
       OR current_revision = 0;
   INSERT rate_date_resolution ... ON CONFLICT (requested_date) DO UPDATE
       SET effective_date = EXCLUDED.effective_date, ... WHERE rate_date_resolution.effective_date <> EXCLUDED.effective_date;
   UPDATE fetch_job SET status='completed', ... WHERE id = :job AND lease_token = :token;  -- 0 строк → lease потерян, ROLLBACK
   ```
3. После commit: инвалидация кэша `rate_set.{E}` и `resolution.{D…}` (§6).

Если транзакция упала, нет ни частичного набора, ни ревизии без котировок. Если процесс упал после commit,
повторная доставка увидит `completed` или совпадающий `content_hash`, и дубля не будет.

---

## 6. Кэш (Redis)

Пул в `config/packages/cache.php`:

```php
'cache.exchange_rate' => ['adapter' => 'cache.app', 'default_lifetime' => '%env(int:EXCHANGE_RATE_CACHE_TTL)%'],
```

| Ключ             | Значение                                                                                          | TTL                                                             |
|------------------|---------------------------------------------------------------------------------------------------|-----------------------------------------------------------------|
| `er.set.{Y-m-d}` | сериализованный `RateSet` (effective_date, revision, котировки строками, previous_effective_date) | 7 дней                                                          |
| `er.res.{Y-m-d}` | `effective_date` для календарной даты                                                             | 7 дней для прошлых дат; для `today` до 00:00 МСК следующего дня |

Подтверждённые данные кэшируются, неподтверждённые никогда. Отсутствие данных не кэшируется,
чтобы не держать «нет данных» после загрузки.

`RedisRateSetCacheInterface` работает в режиме **fail-open** (Flow C): любое исключение адаптера логируется (`warning`,
только
класс ошибки) и считается промахом. Запись в кэш — best effort. Чтобы не тратить
секунду таймаута на каждый вызов при упавшем Redis, после ошибки кэш на 30 с выключается в памяти
процесса (простой circuit breaker).

Корректировка ЦБ (новая ревизия) → `deleteItem('er.set.{E}')`. Ревизия хранится в значении,
поэтому читатель может сравнить её с БД при `--no-cache`.

Демонстрация кэширования: `GetExchangeRateView.source` = `cache` | `database` | `cbr`. Показывается
в консоли и в `meta` HTTP-ответа. Сценарий в README:

```bash
$ docker compose exec app bin/console app:exchange-rate:get USD --date=2026-10-20 -v   # source: cbr
$ docker compose exec app bin/console app:exchange-rate:get USD --date=2026-10-20 -v   # source: cache
$ docker compose exec redis redis-cli FLUSHDB
$ docker compose exec app bin/console app:exchange-rate:get USD --date=2026-10-20 -v   # source: database
```

---

## 7. Получение курса: use case

### 7.1. Алгоритм `GetExchangeRateHandler`

```
input: currency, base = RUB, date = today_msk

1. Валидация: CurrencyCode::fromString ×2, дата 'Y-m-d' не раньше 1992-07-01.
2. Будущая дата: date > today_msk + 1 → RATE_NOT_PUBLISHED (без обращения к ЦБ).
3. Проверка справочника (§4.6): UNKNOWN / UNSUPPORTED.
4. E  = resolve(date)          ─┐ resolve(): cache → DB → ensureLoaded(date)
5. P  = previousOf(E)          ─┘ previousOf(): rate_set.previous_effective_date
                                 или resolve(E − 1), сохранить в rate_set.previous_effective_date
6. current = set(E), previous = set(P)        (cache → DB)
7. RateCalculator::exchangeRate → ExchangeRateView
```

`ensureLoaded(D)` (интерактивная загрузка, Flow B):

```
job = FetchJobRepository::upsertActive(D, priority: interactive, origin: interactive)
      -- INSERT ... ON CONFLICT (request_date) WHERE active DO UPDATE SET priority = GREATEST(...)
      -- уже существующее фоновое задание повышается до interactive (Flow G)
deadline = now + EXCHANGE_RATE_INTERACTIVE_WAIT
loop until deadline:
    если resolution(D) появилась → return
    lease = jobs.tryClaim(job.id)        -- задание свободно → выполняем сами
    если lease:
        outcome = FetchRatesForDate.execute(lease, priority: interactive, maxLimiterWait: deadline − now)
        completed → return; deferred → бросаем соответствующую ошибку (§14)
    иначе (выполняет другой процесс) → sleep 200 мс
timeout → jobs.dispatch(job) в cbr_interactive; throw LOADING_IN_PROGRESS (retryAfter = 5)
```

Интерактивный запрос выполняется **в процессе клиента** через тот же `FetchRatesForDate`, что и воркер.
Это максимальный приоритет без ожидания очереди, и при этом соблюдаются все общие правила:
single-flight через lease, общий лимитер, глобальная пауза. Уже начатые фоновые обращения не прерываются.

### 7.2. Отказ зависимостей в use case

| Сбой                  | Поведение                                                                                                                   |
|-----------------------|-----------------------------------------------------------------------------------------------------------------------------|
| Redis (кэш)           | Чтение из PostgreSQL, ответ без ошибки.                                                                                     |
| Redis (лимитер/пауза) | Данные из БД/кэша отдаются. Новая загрузка **не** выполняется (fail-closed) → `SOURCE_UNAVAILABLE`.                         |
| PostgreSQL            | Если `res` и оба `set` есть в кэше — успешный ответ. Иначе `SERVICE_UNAVAILABLE`, задания не создаются.                     |
| ЦБ                    | Сохранённые данные отдаются. Для отсутствующих — `SOURCE_UNAVAILABLE` / `SOURCE_RATE_LIMITED`, задание остаётся `deferred`. |

### 7.3. Будущие даты

| Запрошено (сегодня 26.10, 15:00 МСК) | Ответ ЦБ               | Результат                                           |
|--------------------------------------|------------------------|-----------------------------------------------------|
| 26.10                                | Date=24.10             | OK, текущий набор 24.10                             |
| 27.10, ЦБ ещё не опубликовал         | Date=24.10 (< 27.10)   | `RATE_NOT_PUBLISHED`, resolution **не** сохраняется |
| 27.10, опубликовано                  | Date=27.10             | OK                                                  |
| 29.10                                | — (запрос не делается) | `RATE_NOT_PUBLISHED`                                |

Общее правило сохранения resolution (D → E): `D ≤ today_msk` **или** `E = D`.

### 7.4. Таблица дат (ФС §4.3) на условной неделе

| Запрос   | Текущий набор E | Предыдущий P | Как получено P                             |
|----------|-----------------|--------------|--------------------------------------------|
| Пт 23.10 | 23.10           | 22.10        | ЦБ на 22.10 → Date=22.10                   |
| Сб 24.10 | 24.10           | 23.10        | ЦБ на 23.10                                |
| Вс 25.10 | 24.10           | 23.10        | resolution(25.10) выведен из запроса 26.10 |
| Пн 26.10 | 24.10           | 23.10        |                                            |
| Вт 27.10 | 27.10           | 24.10        | ЦБ на 26.10 → Date=24.10                   |

---

## 8. Загрузка набора: FetchRatesForDate

Один сервис для трёх вызывающих: inline-запрос клиента, `FetchRatesMessageHandler`, polling.

```php
final readonly class FetchRatesForDate
{
    public function execute(Lease $lease, JobPriority $priority, ?int $maxLimiterWaitMs = null): FetchOutcome
    {
        $job = $lease->job;

        if (!$job->forceRefresh && $this->resolutions->find($job->requestDate) !== null) {
            $this->jobs->complete($lease);                       // уже выведено/загружено другим
            return FetchOutcome::alreadyDone();
        }
        if (null !== $until = $this->pause->pausedUntil()) {
            $this->jobs->defer($lease, DeferReason::SourcePaused, availableAt: null); // время берётся из паузы
            return FetchOutcome::deferred(DeferReason::SourcePaused, $until);
        }

        $permit = $this->limiter->acquire($priority, $maxLimiterWaitMs ?? 5_000);
        if ($permit instanceof WaitFor) {
            $this->jobs->defer($lease, DeferReason::RateLimited, $this->clock->now()->modify("+{$permit->ms} ms"));
            return FetchOutcome::deferred(DeferReason::RateLimited, ...);
        }

        $this->jobs->heartbeat($lease);                          // продлить lease перед сетевым вызовом
        try {
            $response = $this->gateway->fetchDaily($job->requestDate);
        } catch (SourceRateLimited $e) {
            $this->pause->pauseUntil($e->retryAt);               // глобально, для всех экземпляров
            $this->jobs->defer($lease, DeferReason::SourcePaused, availableAt: null);
            return ...;
        } catch (SourceUnavailable|SourceInvalidResponse $e) {
            $this->jobs->failOrDefer($lease, $e, backoff: $this->backoff($job->attempts));
            return ...;
        }

        $this->store->persist($response, $lease);                // §5.4: raw → нормализация → resolution → complete
        $this->registerFollowUps($response->effectiveDate, $priority); // E−1, если previous не известен
        return FetchOutcome::completed($response->effectiveDate);
    }
}
```

**Follow-up предыдущего набора.** После сохранения `E` проверяется `rate_set.previous_effective_date`.
Если его нет и нет `resolution(E−1)`, создаётся задание на `E−1` с тем же приоритетом. Так закрывается
граница backfill (ФС §9: предыдущий набор для самой ранней даты).

**Backoff временных ошибок:** `min(30 с · 2^attempts, 1 ч) ± 20 % jitter`. Состояние `failed`
после `EXCHANGE_RATE_MAX_ATTEMPTS = 10` или при неустранимой ошибке (например, XML разобран,
но `Date` > `date_req`). Если причина — пауза или лимитер, `attempts` **не** увеличивается (ФС Flow I).

---

## 9. Задания, брокер, приоритеты

### 9.1. Жизненный цикл `fetch_job`

```
             upsertActive
                 │
                 ▼
            ┌─────────┐  tryClaim (lease 90 с)  ┌─────────┐   complete(lease)   ┌───────────┐
            │ pending │ ──────────────────────▶ │ running │ ──────────────────▶ │ completed │
            └─────────┘                         └─────────┘                     └───────────┘
                 ▲   available_at ≤ now           │   │ defer(reason)
                 │   и пауза закончилась           │   ▼
            ┌──────────┐ ◀────────────────────────┘ ┌──────────┐
            │ deferred │ ◀── lease_expires_at < now  │  failed  │ ◀── attempts ≥ max / fatal
            └──────────┘     (DispatchReadyJobs)     └──────────┘
```

Захват (`tryClaim`) — одной командой, без гонок:

```sql
UPDATE fetch_job
   SET status = 'running', lease_token = :token, lease_expires_at = now() + interval '90 seconds',
       attempts = attempts + CASE WHEN defer_reason IN ('source_paused','rate_limited') THEN 0 ELSE 1 END,
       updated_at = now()
 WHERE id = :id
   AND (   (status IN ('pending','deferred') AND available_at <= now())
        OR (status = 'running' AND lease_expires_at < now()))
RETURNING *;
```

Все последующие изменения задания выполняются с условием `WHERE id = :id AND lease_token = :token`.
Если исполнитель запоздал (lease истёк, задание забрал другой), он получает 0 строк, его транзакция
откатывается, данные не перезаписываются (Flow J). Дополнительную защиту дают `content_hash` и
`source_fetched_at` (§5.4).

### 9.2. Транспорты Messenger

`config/packages/messenger.php` — добавить к существующим `async`/`failed`:

```php
'cbr_interactive' => [
    'dsn' => '%env(MESSENGER_TRANSPORT_DSN)%',
    'options' => [...$connectionOptions,
        'exchange' => ['name' => 'cbr_interactive', 'type' => 'direct', 'default_publish_routing_key' => 'cbr_interactive'],
        'queues'   => ['cbr_interactive' => ['binding_keys' => ['cbr_interactive']]],
    ],
    // Повторы управляются fetch_job, не Messenger: retry Messenger только для сбоев инфраструктуры.
    'retry_strategy' => ['max_retries' => 3, 'delay' => 2000, 'multiplier' => 2],
],
'cbr_background' => [ /* то же, имя cbr_background */ ],
'scheduler_exchange_rate' => 'schedule://exchange_rate',
],
'routing' => [
    FetchRatesMessage::class => 'cbr_background',   // interactive выбирается stamp'ом TransportNamesStamp
    PollPublicationMessage::class => 'cbr_interactive',
    DispatchReadyJobsMessage::class => 'async',
    ReconcileHistoryMessage::class => 'async',
],
```

`MessengerJobDispatcher::dispatch(FetchJob $job)` добавляет `new TransportNamesStamp(['cbr_interactive'])`
для заданий с `priority = interactive`.

Воркер читает транспорты **в порядке приоритета** (Symfony сначала проверяет первый транспорт):

```yaml
command: ["php", "-d", "max_execution_time=0", "bin/console", "messenger:consume",
          "cbr_interactive", "cbr_background", "async", "--time-limit=3600", "--memory-limit=128M", "-v"]
```

### 9.3. Сообщение — указатель, истина в БД

`FetchRatesMessage { string $jobId }`. Обработчик:

```php
#[AsMessageHandler]
final readonly class FetchRatesMessageHandler
{
    public function __invoke(FetchRatesMessage $m): void
    {
        $lease = $this->jobs->tryClaim($m->jobId);
        if (null === $lease) {
            return;               // уже выполнено, выполняется или ещё не время — ack без действий
        }
        $job = $lease->job;
        $this->fetch->execute($lease, $job->priority);   // никогда не бросает бизнес-ошибки
    }
}
```

Повторная доставка безопасна: второй `tryClaim` вернёт `null`. Если сообщение потеряно или RabbitMQ
недоступен, задание остаётся в БД, и `DispatchReadyJobs` опубликует его снова (§12.2). Потерянное сообщение
не означает потерянное задание.

### 9.4. Приоритеты и отсутствие голодания (Flow G)

- Интерактивное задание выполняется inline (§7.1). Если оно ушло в очередь, то попадает в `cbr_interactive`, который
  воркеры читают первым.
- Существующее фоновое задание на ту же дату повышается (`priority = GREATEST(priority, 100)`). Нового задания не
  создаётся.
- Лимитер резервирует часть бюджета под интерактив (§10.1), но фон получает токены при каждом пополнении. Поток клиентов
  не может занять весь бюджет навсегда, потому что токены сверх резерва достаются всем по очереди.
- `DispatchReadyJobs` публикует задания в порядке `priority DESC, available_at`.

---

## 10. Ограничение нагрузки и глобальная пауза

### 10.1. Глобальный token bucket (Redis + Lua)

Ключ `er:cbr:bucket` (hash `tokens`, `ts_ms`). Время берётся из Redis `TIME`, поэтому расхождение часов
экземпляров не влияет. Параметры: `EXCHANGE_RATE_CBR_RPS = 1`, `EXCHANGE_RATE_CBR_BURST = 3`,
`EXCHANGE_RATE_CBR_INTERACTIVE_RESERVE = 1`.

```lua
-- KEYS[1]=bucket KEYS[2]=pause_until  ARGV: rate_per_ms, burst, reserve, is_interactive
local t = redis.call('TIME'); local now = t[1]*1000 + math.floor(t[2]/1000)
local pause = tonumber(redis.call('GET', KEYS[2]) or '0')
if pause > now then return {0, pause - now} end
local b = redis.call('HMGET', KEYS[1], 'tokens', 'ts')
local tokens = tonumber(b[1] or ARGV[2]); local ts = tonumber(b[2] or now)
tokens = math.min(tonumber(ARGV[2]), tokens + (now - ts) * tonumber(ARGV[1]))
local need = (ARGV[4] == '1') and 1 or (1 + tonumber(ARGV[3]))   -- фон не берёт резерв
if tokens >= need then
    redis.call('HSET', KEYS[1], 'tokens', tokens - 1, 'ts', now)
    redis.call('PEXPIRE', KEYS[1], 60000)
    return {1, 0}
end
redis.call('HSET', KEYS[1], 'tokens', tokens, 'ts', now)
return {0, math.ceil((need - tokens) / tonumber(ARGV[1]))}
```

`RedisTokenBucketLimiter::acquire($priority, $maxWaitMs)`: если wait ≤ maxWaitMs, ждёт (`usleep`)
и пробует снова. Иначе возвращает `WaitFor($ms)`. Ошибка Redis → `WaitFor` с причиной
`limiter_unavailable` (**fail-closed**: без лимитера к ЦБ не обращаемся).

Лимит не зависит от числа процессов: 5 экземпляров × 40 воркеров = максимум `RPS` запросов в секунду.
Polling, reconcile и справочник идут через тот же лимитер.

### 10.2. Глобальная пауза (Flow H)

- `429` → `SET er:cbr:pause_until <retry_at_ms>` (если новое значение больше текущего, атомарно через Lua) + запись в
  лог и в `cbr_fetch_attempt`.
- Пока пауза активна, Lua-скрипт лимитера отказывает всем. Задания переводятся в `deferred` с
  `defer_reason = 'source_paused'`, `available_at` **не меняется**.
- Эффективная готовность задания = `max(available_at, pause_until)`. Её вычисляет `DispatchReadyJobs`, поэтому 360
  заданий не перепланируются по отдельности, и продление паузы ничего не стоит.
- Когда пауза заканчивается, `DispatchReadyJobs` публикует задания пачками (`batch = EXCHANGE_RATE_DISPATCH_BATCH = 5`
  за тик в 5 с), лимитер дополнительно сглаживает поток. Одновременного залпа нет.
- Redis хранит ключ с AOF (уже включено в compose). Если ключ потерян, следующий `429` установит его снова.

---

## 11. Backfill

```bash
docker compose exec app bin/console app:exchange-rate:backfill               # 180 дней
docker compose exec app bin/console app:exchange-rate:backfill --days=360
docker compose exec app bin/console app:exchange-rate:backfill --days=30 --force   # перепроверить загруженное
```

`StartBackfill::run(days, force)`:

1. `today = MoscowClock::today()`. Даты: `today−1 … today−days`, только завершённые календарные даты.
2. Отбрасываются даты, у которых уже есть `rate_date_resolution` (кроме `--force`).
3. Для остальных одним запросом:
   `INSERT INTO fetch_job ... SELECT unnest(:dates) ... ON CONFLICT (request_date) WHERE status IN (...) DO NOTHING RETURNING id`.
4. Созданные задания публикуются в `cbr_background` (`dispatched_at = now()`).
5. Команда печатает сводку и завершается. Загрузку выполняют воркеры.

Пример вывода:

```
$ bin/console app:exchange-rate:backfill
Backfill 2026-04-29 … 2026-10-25 (180 дней, МСК)

 ------------------------ -------
  Уже подтверждено          12
  Уже в очереди              0
  Поставлено в очередь     168
 ------------------------ -------

Задания обрабатываются воркерами. Прогресс: bin/console app:exchange-rate:status --watch
```

Повторный запуск во время работы печатает `Уже в очереди: N` и `Поставлено: 0`. После завершения —
`Уже подтверждено: 180`. Дубликатов нет: это обеспечивают `fetch_job_active_date` и `rate_date_resolution`.

`--force` создаёт задания с `force_refresh = true`. Для них resolution не пропускает загрузку, а
результат сравнивается по `content_hash`. Новая ревизия появится только при реальном изменении.

---

## 12. Планировщик: polling, dispatch, reconciliation

Нужны `symfony/scheduler` и `symfony/lock` (§16.2). Расписание:

```php
#[AsSchedule('exchange_rate')]
final readonly class ExchangeRateSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return new Schedule()
            ->add(RecurringMessage::every('5 seconds', new DispatchReadyJobsMessage()))
            ->add(RecurringMessage::every('1 minute', new PollPublicationMessage()))   // частоту решает политика
            ->add(RecurringMessage::every('15 minutes', new ReconcileHistoryMessage()))
            ->stateful($this->cache)                                    // переживает рестарт
            ->lock($this->lockFactory->createLock('schedule_exchange_rate'));  // один активный планировщик
    }
}
```

Отдельный сервис compose `scheduler` (§16.3) выполняет `messenger:consume scheduler_exchange_rate`.
При нескольких экземплярах lock (Redis store) гарантирует, что тик сработает один раз.

### 12.1. PollPublication (Flow F)

`PollingSchedulePolicy::shouldPoll(now_msk, lastPollAt)` по конфигурации:

```php
'exchange_rate.polling' => [
    ['from' => '00:00', 'to' => '09:00', 'every' => null],
    ['from' => '09:00', 'to' => '14:00', 'every' => 'PT1H'],
    ['from' => '14:00', 'to' => '17:00', 'every' => 'PT30M'],
    ['from' => '17:00', 'to' => '20:00', 'every' => 'PT10M'],
    ['from' => '20:00', 'to' => '23:00', 'every' => 'PT1H'],
    ['from' => '23:00', 'to' => '24:00', 'every' => null],
],
'exchange_rate.polling_weekend_multiplier' => 2,
```

`lastPollAt` хранится в Redis (`er:poll:last`). Слот закрепляется через `SET er:poll:slot:{slot} NX EX`,
чтобы два планировщика не опросили ЦБ дважды. Шаги:

1. Политика сейчас запрещает опрос → выход.
2. `limiter.acquire(interactive, 0)`. Не получилось → выход, попытка на следующем тике.
3. `gateway.fetchDaily(null)`: последний набор `E`.
4. `E` неизвестна → сохранить (§5.4, resolution только для `E`) + follow-up `E−1`.
5. `E` известна и хэш совпал → выход. Хэш другой → новая ревизия (корректировка), инвалидация кэша.

Лог: `info` `Exchange rate poll` `{effective_date, outcome: new|unchanged|corrected}`.

### 12.2. DispatchReadyJobs (каждые 5 с)

```sql
-- 1. Вернуть просроченные lease (упавшие исполнители)
UPDATE fetch_job SET status='deferred', defer_reason='lease_expired', lease_token=NULL, updated_at=now()
 WHERE status='running' AND lease_expires_at < now();

-- 2. Если er:cbr:pause_until > now — дальше не идём.
-- 3. Опубликовать готовые, ещё не опубликованные или опубликованные давно (сообщение потеряно)
SELECT id, priority FROM fetch_job
 WHERE status IN ('pending','deferred') AND available_at <= now()
   AND (dispatched_at IS NULL OR dispatched_at < now() - interval '2 minutes')
 ORDER BY priority DESC, available_at
 LIMIT :batch FOR UPDATE SKIP LOCKED;
-- → publish, UPDATE dispatched_at = now()
```

Если RabbitMQ недоступен, публикация падает, `dispatched_at` не обновляется. На следующем тике
после восстановления брокера доставка продолжается.

### 12.3. ReconcileHistory (Flow K, каждые 15 мин)

1. Диапазон контроля: `today−1 … today−EXCHANGE_RATE_RECONCILE_DAYS` (по умолчанию 180).
2. Даты без `rate_date_resolution` и без активного задания → `fetch_job` (origin `reconcile`, background).
3. Наборы без `previous_effective_date` → follow-up `E−1`.
4. `failed` задания с временной причиной старше 6 ч → снова в `pending` с `attempts = 0`. Одна попытка «оживления» в
   сутки.
5. Раз в сутки в 21:00 МСК: force-задания на последние `EXCHANGE_RATE_RECHECK_DAYS` дней (корректировки ЦБ).

После нескольких дней простоя reconcile создаёт задания на пропущенные даты, а polling подхватывает
последний набор.

---

## 13. Интерфейсы: Console и HTTP

### 13.1. `GetExchangeRateCommand`

`app/src/Modules/ExchangeRate/Endpoints/Console/GetExchangeRateCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\ExchangeRate\Endpoints\Console;

use App\Cources\Domain\Exception\ExchangeRate\ExchangeRateException;use App\Modules\ExchangeRate\Application\GetExchangeRate\ExchangeRateView;use App\Modules\ExchangeRate\Application\GetExchangeRate\GetExchangeRateHandler;use App\Modules\ExchangeRate\Application\GetExchangeRate\GetExchangeRateQuery;use Symfony\Component\Console\Attribute\Argument;use Symfony\Component\Console\Attribute\AsCommand;use Symfony\Component\Console\Attribute\Option;use Symfony\Component\Console\Command\Command;use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:exchange-rate:get',
    description: 'Официальный курс ЦБ РФ, предыдущий курс и изменение',
    help: <<<'HELP'
        <info>%command.full_name% USD</info>                    USD/RUB на сегодня (МСК)
        <info>%command.full_name% inr usd --date=2026-10-26</info>  кросс-курс INR/USD
        <info>%command.full_name% EUR --format=json</info>      тот же контракт, что у HTTP API
        HELP,
)]
final readonly class GetExchangeRateCommand
{
    public function __construct(
        private GetExchangeRateHandler $handler,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Код валюты (ISO 4217), регистр не важен')] string $currency,
        #[Argument('Базовая валюта; RUR и RUB — российский рубль')] string $base = 'RUR',
        #[Option('Дата курса Y-m-d; по умолчанию сегодня по Москве')] ?string $date = null,
        #[Option('table | json')] string $format = 'table',
    ): int {
        try {
            $view = $this->handler->handle(new GetExchangeRateQuery($currency, $base, $date));
        } catch (ExchangeRateException $e) {
            'json' === $format
                ? $io->writeln(json_encode(['error' => $e->toArray()], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT))
                : $io->error(\sprintf('[%s] %s', $e->errorCode->value, $e->getMessage()));

            return ErrorExitCode::of($e->errorCode);
        }

        'json' === $format ? $this->renderJson($io, $view) : $this->renderTable($io, $view);

        return Command::SUCCESS;
    }

    private function renderTable(SymfonyStyle $io, ExchangeRateView $v): void
    {
        $io->horizontalTable(
            ['Дата запроса', 'Пара', 'Курс', 'Дата действия', 'Предыдущий курс', 'Предыдущая дата', 'Изменение'],
            [[$v->requestedDate, $v->currency.'/'.$v->base, $v->rate, $v->effectiveDate,
              $v->previousRate, $v->previousEffectiveDate, $v->signedDiff()]],
        );
        if ($io->isVerbose()) {
            $io->comment(\sprintf('Источник данных: %s; ревизии наборов: %d / %d', $v->source, $v->revision, $v->previousRevision));
        }
    }

    private function renderJson(SymfonyStyle $io, ExchangeRateView $v): void
    {
        $io->writeln(json_encode($v->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT));
    }
}
```

> Invokable-команды с `#[Argument]`/`#[Option]` доступны в Symfony 7.3+. Проект на 7.4, так что `extends Command`
> не нужен. Дефолт `RUR` остаётся строкой: нормализация в RUB — дело домена, а не консоли.

Вывод:

```
$ bin/console app:exchange-rate:get usd --date=2026-10-26
 ----------------- ------------
  Дата запроса      2026-10-26
  Пара              USD/RUB
  Курс              81.4500
  Дата действия     2026-10-24
  Предыдущий курс   81.2000
  Предыдущая дата   2026-10-23
  Изменение         +0.2500
 ----------------- ------------

$ bin/console app:exchange-rate:get INR USD --date=2026-10-26 --format=json
{
    "requestedDate": "2026-10-26",
    "currency": "INR",
    "base": "USD",
    "effectiveDate": "2026-10-24",
    "rate": "0.011894290976058931860036832412",
    ...
}

$ bin/console app:exchange-rate:get XYZ
 [ERROR] [UNKNOWN_CURRENCY] Валюта XYZ отсутствует в справочнике Банка России
$ echo $?
3
```

(Полный JSON-контракт приведён в §13.3. Он одинаков для консоли и HTTP.)

### 13.2. Прочие команды

| Команда                                             | Назначение                                                                                                                                                                                          |
|-----------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `app:exchange-rate:backfill [--days=180] [--force]` | §11                                                                                                                                                                                                 |
| `app:exchange-rate:status [--watch]`                | Наблюдаемость: число заданий по статусам и причинам отложения, `pause_until`, токены лимитера, последний polling, последняя дата действия, число попыток к ЦБ за 1 ч / 24 ч по `cbr_fetch_attempt`. |

```
$ bin/console app:exchange-rate:status
 Задания: pending 41 · running 3 · deferred 12 (source_paused 12) · completed 124 · failed 0
 Источник: ПАУЗА до 2026-10-26 15:42:10 МСК (429), лимит 1 rps / burst 3, токенов 0.0
 Последний polling: 15:30 (unchanged, набор 2026-10-27 r1)
 Обращений к ЦБ: 1 ч — 118, 24 ч — 131
```

### 13.3. JSON-контракт (консоль `--format=json` и HTTP)

Успех:

```json
{
  "requestedDate": "2026-10-26",
  "currency": "INR",
  "base": "USD",
  "effectiveDate": "2026-10-24",
  "rate": "0.011894290976058931860036832412",
  "previousEffectiveDate": "2026-10-23",
  "previousRate": "0.011884236453201970443349753694",
  "diff": "0.000010054522856961416687078718",
  "meta": {
    "precision": { "mode": "internal", "scale": 30 },
    "source": "database",
    "revisions": { "current": 1, "previous": 1 }
  }
}
```

Для `USD/RUB`: `"precision": {"mode": "exact", "scale": 4}`.

Ошибка (HTTP: `application/problem+json`, RFC 9457):

```json
{
  "type": "https://cb-cources.local/errors/loading-in-progress",
  "title": "Данные загружаются",
  "status": 503,
  "code": "LOADING_IN_PROGRESS",
  "retryable": true,
  "retryAfter": 5,
  "detail": "Курсы на 2026-03-02 ещё загружаются из Банка России, повторите запрос позже",
  "details": { "date": "2026-03-02" }
}
```

### 13.4. HTTP (этап 2)

`GET /api/v1/exchange-rates?currency=INR&base=USD&date=2026-10-26`. Контроллер в `Endpoints/Http`
вызывает тот же `GetExchangeRateHandler`. `ProblemJsonExceptionListener` превращает
`ExchangeRateException` в problem+json. `Retry-After` — для `retryable`. Заголовки кэширования:
`Cache-Control: public, max-age=86400` для прошлых дат, `max-age=300` для сегодняшней.

---

## 14. Ошибки

`Domain/Exception/ErrorCode.php` — enum, единый для консоли и HTTP:

| Код                       | Когда                                     | HTTP | Exit | retryable                         |
|---------------------------|-------------------------------------------|------|------|-----------------------------------|
| `INVALID_ARGUMENT`        | формат валюты/даты, дата < 1992-07-01     | 400  | 2    | нет                               |
| `UNKNOWN_CURRENCY`        | §4.6                                      | 404  | 3    | нет                               |
| `UNSUPPORTED_CURRENCY`    | только ежемесячная котировка              | 422  | 3    | нет                               |
| `RATE_NOT_FOUND_FOR_DATE` | набор подтверждён, валюты в нём нет       | 404  | 4    | нет                               |
| `PREVIOUS_RATE_NOT_FOUND` | в предыдущем наборе валюты нет            | 404  | 4    | нет                               |
| `RATE_NOT_PUBLISHED`      | будущая дата, курс не установлен          | 404  | 4    | да (для завтрашней даты)          |
| `LOADING_IN_PROGRESS`     | не уложились в ожидание                   | 503  | 75   | да                                |
| `SOURCE_UNAVAILABLE`      | ЦБ недоступен / лимитер недоступен        | 503  | 75   | да                                |
| `SOURCE_RATE_LIMITED`     | активна глобальная пауза                  | 503  | 75   | да (`retryAfter` = остаток паузы) |
| `SOURCE_INVALID_RESPONSE` | XML не разобран                           | 502  | 75   | да                                |
| `SERVICE_UNAVAILABLE`     | PostgreSQL недоступен и в кэше нет данных | 503  | 75   | да                                |
| `INTERNAL_ERROR`          | всё прочее                                | 500  | 1    | нет                               |

`75` = `EX_TEMPFAIL` (sysexits): скрипт может отличить временную ошибку от окончательной по коду выхода.
Успешный ответ возможен, только если подтверждены и `E`, и `P` (ФС §19).

---

## 15. Конфигурация

`config/packages/exchange_rate.php` (параметры) + переменные в `app/.env.dist`, `compose.yaml`
(`x-app-environment`) и `.env.example`:

| Переменная                              | По умолчанию         | Назначение                                        |
|-----------------------------------------|----------------------|---------------------------------------------------|
| `CBR_BASE_URL`                          | `https://www.cbr.ru` | Базовый URL. В тестах подменяется MockHttpClient. |
| `EXCHANGE_RATE_CBR_RPS`                 | `1`                  | Глобальный лимит запросов в секунду               |
| `EXCHANGE_RATE_CBR_BURST`               | `3`                  | Ёмкость bucket                                    |
| `EXCHANGE_RATE_CBR_INTERACTIVE_RESERVE` | `1`                  | Токены, недоступные фону                          |
| `EXCHANGE_RATE_INTERACTIVE_WAIT`        | `8`                  | Секунды ожидания клиента                          |
| `EXCHANGE_RATE_SIGNIFICANT_DIGITS`      | `12`                 | Значащие цифры кросс-курса                        |
| `EXCHANGE_RATE_CACHE_TTL`               | `604800`             | TTL кэша, с                                       |
| `EXCHANGE_RATE_BACKFILL_DAYS`           | `180`                | Дефолт `--days`                                   |
| `EXCHANGE_RATE_RECONCILE_DAYS`          | `180`                | Диапазон контроля полноты                         |
| `EXCHANGE_RATE_RECHECK_DAYS`            | `10`                 | Глубина перепроверки корректировок                |
| `EXCHANGE_RATE_MAX_ATTEMPTS`            | `10`                 | Попыток до `failed`                               |
| `EXCHANGE_RATE_DISPATCH_BATCH`          | `5`                  | Публикаций за тик dispatcher                      |

Расписание polling — массив-параметр (§12.1), меняется в конфиге без правки кода.

---

## 16. Изменения инфраструктуры репозитория

### 16.1. Deptrac

`Endpoints` добавляется в слой Presentation:

```yaml
-   name: Presentation
    collectors:
        -   type: directory
            value: src/(Modules|Support)/[^/]+/(Presentation|Endpoints)/.*
```

Другой вариант — переименовать `Endpoints` в `Presentation`, как в `Support/Health`. Нужно выбрать один
и обновить README §3. `BcMath\Number` и `\RoundingMode` — классы ядра PHP, Deptrac их не собирает,
поэтому в Domain они разрешены.

### 16.2. Composer

```bash
composer require symfony/scheduler:7.4.* symfony/lock:7.4.*
```

Lock store: `framework.lock = '%env(REDIS_URL)%'` (Redis уже есть).

### 16.3. Compose

```yaml
    worker:
        command: ["php", "-d", "max_execution_time=0", "bin/console", "messenger:consume",
                  "cbr_interactive", "cbr_background", "async",
                  "--time-limit=3600", "--memory-limit=128M", "--no-interaction", "-v"]
        deploy:
            replicas: ${WORKER_REPLICAS:-2}

    # Планировщик: тики polling/dispatch/reconcile. Несколько реплик безопасны (lock).
    scheduler:
        image: ${COMPOSE_PROJECT_NAME:-cb-course}-app:prod
        command: ["php", "-d", "max_execution_time=0", "bin/console", "messenger:consume",
                  "scheduler_exchange_rate", "--time-limit=3600", "--memory-limit=128M", "--no-interaction", "-v"]
        init: true
        environment: *app-environment
        depends_on: { <<: *app-depends-on, app: { condition: service_healthy } }
        restart: unless-stopped
```

В `compose.dev.yaml` добавить `scheduler: <<: *dev-app`. `HEALTH_CHECK_RABBITMQ` для prod
лучше включить: без брокера backfill не работает.

### 16.4. HTTP (этап 2)

Вернуть `nginx` + `php-fpm` (их Dockerfile и `default.conf` всё ещё в `.docker/`) либо отдельный
сервис `http` на FrankenPHP. Решается вместе с README.

### 16.5. README

Добавить разделы: «Как получить курс» (примеры §13.1), «Загрузка истории» (§11, проверка через
`status` и RabbitMQ UI http://localhost:15672), «Демонстрация кэша» (§6), «Сценарии ошибок» (§17.3), «Принятые решения»
(§1).

---

## 17. Тесты

Фикстуры: `tests/Fixtures/Cbr/daily_2026-10-23.xml`, `daily_2026-10-24.xml`, `daily_2026-10-24_corrected.xml`,
`daily_invalid.xml`, `valFull_d0.xml`, `valFull_d1.xml`. Файлы в **windows-1251**, как у ЦБ.
В `when@test` клиент `cbr.client` заменяется на `MockHttpClient` с `CbrFixtureResponder`.
Responder сопоставляет `date_req` → файл (в том числе «запрос 26.10 → файл 24.10») и умеет отвечать
`429 Retry-After: 30` / `503` / timeout.

### 17.1. Unit (`tests/Unit/Modules/ExchangeRate/...`)

| Тест                        | Проверяет                                                                                                          |
|-----------------------------|--------------------------------------------------------------------------------------------------------------------|
| `CurrencyCodeTest`          | регистр, `RUR→RUB`, невалидные коды                                                                                |
| `QuoteTest`                 | `unitRate`: USD `81.4500`, INR/100 → `0.968790`, IDR/10000 → `0.00498765`; хвостовые нули сохраняются              |
| `RateCalculatorTest`        | все строки таблицы §4.5 побайтно; `X/X = 1`; `RUB/RUB`                                                             |
| `RateCalculatorTest`        | разница из неокруглённых курсов и внутренний scale 30                                                              |
| `DiffBeforeRoundingTest`    | контрпример §4.5: разница не обнуляется                                                                            |
| `DailyXmlParserTest`        | windows-1251, запятые, `Date` ≠ `date_req`, отсутствие `Value` → `SourceInvalidResponse`, `VunitRate` игнорируется |
| `ContentHashTest`           | порядок валют не влияет; изменение `name` или `Value` влияет                                                       |
| `ResolutionInferenceTest`   | `[E, D]` выводится; для `D > today` вывода нет                                                                     |
| `FutureDateRuleTest`        | таблица §7.3                                                                                                       |
| `PollingSchedulePolicyTest` | границы окон, ночь, выходной множитель, зона Europe/Moscow при системной UTC                                       |
| `FetchJobTest`              | переходы состояний; `attempts` не растёт при паузе                                                                 |

### 17.2. Integration (PostgreSQL, Redis, RabbitMQ из `make test`)

| Тест                      | Проверяет                                                                                                                                                      |
|---------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `GetExchangeRateFlowTest` | Flow A/B: первый вызов `source=cbr` (1 HTTP-запрос + 1 на E−1), второй `cache`, после `FLUSHDB` — `database`; HTTP-счётчик MockHttpClient не растёт            |
| `WeekendResolutionTest`   | пн → набор сб, предыдущий — пт; resolution для вс выведен                                                                                                      |
| `IdempotentPersistTest`   | двойной `persist` одного ответа → 1 raw, 1 ревизия, 0 дублей котировок                                                                                         |
| `CorrectionTest`          | изменённый ответ → ревизия 2, кэш инвалидирован, raw обоих ответов на месте                                                                                    |
| `StaleWorkerTest`         | lease истёк, задание забрал второй исполнитель → `complete` первого возвращает 0 строк, данные второго сохранены                                               |
| `ConcurrentClaimTest`     | 2 соединения `tryClaim` одной строки → ровно один lease                                                                                                        |
| `BackfillTest`            | 180 дат → задания; повторный запуск → 0 новых; после обработки (sync transport в test) → все даты разрешены, есть follow-up за границей диапазона              |
| `TokenBucketTest`         | при `RPS = 1` 10 `acquire` за 1 с → не больше `burst` разрешений; фон не берёт резерв                                                                          |
| `GlobalPauseTest`         | `429` → `pause_until` установлен, все задания `deferred/source_paused`, `available_at` не изменился; после паузы dispatcher публикует не больше `batch` за тик |
| `CacheDownTest`           | `REDIS_URL` на закрытый порт → курс из БД; новая загрузка → `SOURCE_UNAVAILABLE` (лимитер fail-closed)                                                         |
| `DispatcherRecoveryTest`  | задание без `dispatched_at` (брокер был недоступен) публикуется; lease с истёкшим сроком → `deferred/lease_expired`                                            |

### 17.3. Сценарии ошибок для README (проверяемые вручную)

```bash
bin/console app:exchange-rate:get US                 # INVALID_ARGUMENT, exit 2
bin/console app:exchange-rate:get XYZ                # UNKNOWN_CURRENCY, exit 3
bin/console app:exchange-rate:get USD --date=2030-01-01   # RATE_NOT_PUBLISHED, exit 4
docker compose stop redis && bin/console app:exchange-rate:get USD --date=2026-10-20 -v   # source: database
```

---

## 18. Порядок реализации

Каждый шаг завершается зелёными `make test` и `make qa`.

1. **Домен и точность:** `CurrencyCode`, `RateItem`, `RateSet`, `RateCalculator` + unit-тесты §17.1. Deptrac: слой
   `Endpoints`.
2. **Источник:** `DailyXmlParser`, `HttpCbrGateway`, фикстуры, `CbrFixtureResponder`.
3. **Хранилище:** миграция §5, DBAL-репозитории, `persist` (§5.4), inference.
4. **Use case + консоль:** `GetExchangeRateHandler` с inline-загрузкой (без лимитера — заглушка `AllowAll`),
   `GetExchangeRateCommand`, кэш (§6). Минимальная готовая функциональность ТЗ, кроме брокера.
5. **Задания и брокер:** `fetch_job`, транспорты, `FetchRatesMessageHandler`, `BackfillCommand`, `status`. Закрывает ТЗ
   полностью.
6. **Нагрузка:** Redis token bucket, глобальная пауза, fail-closed.
7. **Планировщик:** scheduler + lock, `DispatchReadyJobs`, `PollPublication`, `ReconcileHistory`, compose-сервис
   `scheduler`.
8. **README** по §16.5, прогон из чистого окружения: `git clone && make up && backfill && get`.
9. **(Этап 2)** HTTP API.
