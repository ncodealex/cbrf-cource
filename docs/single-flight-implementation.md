# Реализация Single Flight и Retry

Поведение и ограничения описаны в [rateset-storage.md](rateset-storage.md#single-flight-и-повторы).

## Решения

- Callback блокировки получает RateSetLoadLockGuard: проверка владения и продление до HTTP и до сохранения.
- TTL 30 секунд; HTTP max_duration 15 секунд. Exactly-once и fencing не обеспечиваются.
- Один Symfony token bucket с burst 2 и пополнением 1 токен каждые 3 секунды, строгим Redis-хранилищем и штатным Redis
  `lock_factory`; ошибки Redis не допускают HTTP.
- RetryPolicy получает параметры от вызывающего сценария. Console: 3 вызова, 100/200 мс ±20%, бюджет 300 мс от первого вызова.
- Сохранение Repository не менялось. Worker и новые тесты не добавлены.

## Проверки

- Прогрев dev/test-контейнеров и lint:container --env=test выполнены успешно.
- Deptrac: 0 нарушений, 0 ошибок.
- CS Fixer для изменённых PHP-файлов выполнен. Полный make qa остановился на форматировании 35 остальных файлов; массовое форматирование не выполнялось.
- Отдельный PHPStan: 78 ошибок в существующем коде и тестах. Среди них вызовы старых конструкторов Resolver/Gateway в тестах, требующие адаптации к новым зависимостям. Замечаний к новым классам после исправлений нет. Полный лог: app/var/single-flight-phpstan.log (локальный артефакт).
- PHPUnit не запускался. Тестовые файлы не изменялись; конкурентное поведение с реальным Redis предстоит проверить отдельному исполнителю.

## Изменённые файлы

- `app/config/packages/lock.php`
- `app/config/packages/rate_limiter.php`
- `app/src/Cources/Application/Contract/RateSetLoadLockGuard.php`
- `app/src/Cources/Application/Contract/RateSetLoadLockInterface.php`
- `app/src/Cources/Application/Exception/RateSetLoadBusy.php`
- `app/src/Cources/Application/Exception/RateSetLoadLockFailure.php`
- `app/src/Cources/Endpoints/Console/GetExchangeRate/RetryingGetExchangeRate.php`
- `app/src/Cources/Infrastructure/Cbr/Exception/CbrHttpFailure.php`
- `app/src/Cources/Infrastructure/Cbr/RetryAfterParser.php`
- `app/src/Cources/Infrastructure/Lock/RedisRateSetLoadLock.php`
- `app/src/Cources/Infrastructure/Lock/RedisRateSetLoadLockGuard.php`
- `app/src/Support/RateLimiter/Application/RequestRateLimitExceeded.php`
- `app/src/Support/RateLimiter/Application/RequestRateLimiterFailure.php`
- `app/src/Support/RateLimiter/Infrastructure/RedisRateLimiterStorage.php`
- `app/src/Support/Retry/Application/RetryDecision.php`
- `app/src/Support/Retry/Application/RetryPolicy.php`
- `app/src/Support/Retry/Application/RetryableFailure.php`
- `app/config/packages/lock.yaml` — заменён PHP-конфигурацией
- `app/config/services.php`
- `app/src/Cources/Application/Contract/ExternalRateSetGatewayInterface.php`
- `app/src/Cources/Application/Service/RateSetResolver.php`
- `app/src/Cources/Endpoints/Console/GetExchangeRate/GetExchangeRateCommand.php`
- `app/src/Cources/Infrastructure/Cbr/CbrRateSetGateway.php`
- `app/src/Cources/Infrastructure/Cbr/Exception/CbrRateLimited.php`
- `app/src/Cources/Infrastructure/Cbr/Exception/CbrUnavailable.php`
- `app/src/Support/RateLimiter/Application/RateLimitDecision.php`
- `app/src/Support/RateLimiter/Application/RequestRateLimiter.php`
- `app/src/Support/RateLimiter/Infrastructure/SymfonyRequestRateLimiter.php`
- `docs/rateset-storage.md`
- `docs/single-flight-implementation.md`
