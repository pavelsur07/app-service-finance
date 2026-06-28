## Stage 5: глобальное обогащение Sentry-scope + чистка logSlowExecution — DONE

**Риск:** 🔴 HIGH (новая observability-инфраструктура + изменение Shared-сервиса `AppLogger`)
**Следующее действие:** 🛑 STOP, ждать Владельца (PR)

### Что сделано
1. **`SentryMessengerScopeSubscriber`** (`Shared/Infrastructure/Sentry`) — на каждое принятое воркером сообщение (`WorkerMessageReceivedEvent`) выставляет в Sentry-scope теги `messenger.message` (класс) и `company_id` (если сообщение несёт). Теги перезаписываются на следующем сообщении (воркер последователен — push/pop не нужен; стейл между сообщениями безвреден). companyId извлекается duck-typing'ом (`getCompanyId()` или public `$companyId`) — без связывания Shared с feature-модулями.
2. **`SentryRequestScopeSubscriber`** (`Shared/Infrastructure/Sentry`) — на `kernel.request` (main only) выставляет user id + тег `company_id`. Источник — существующий `AuditContextProvider` (уже безопасно гардит консоль/отсутствие запроса и ловит `NotFoundHttpException`).
3. **`AppLogger::logSlowExecution`** — убран бэкдор в GlitchTip (`sentryHub->captureMessage`). Теперь только warning в файл; зависимость `HubInterface` удалена из конструктора (была нужна только для этого вызова). По конвенции §23: в GlitchTip только ERROR, медленное выполнение — не инцидент.

### Дизайн-решения
- **Без middleware → без правки `messenger.yaml`.** Использован автоконфигурируемый `WorkerMessage*`-подписчик (паттерн уже есть: `SyncJobFailureSubscriber`). Это снимает обязательный STOP на `messenger.yaml`.
- **Без правки `sentry.yaml`.** Scope выставляется на текущем хабе (`HubInterface`), работает поверх существующей конфигурации.
- **`AppLogger` теряет арг `HubInterface`** → обновлены 3 тестовых конструкции (`FetchOzonAdStatisticsHandlerTest` ×2, `ProcessAdRawDocumentHandlerTest`) + убраны неиспользуемые импорты.

### Затронутые файлы
- `src/Shared/Infrastructure/Sentry/SentryMessengerScopeSubscriber.php` — new (`final class`, EventSubscriber)
- `src/Shared/Infrastructure/Sentry/SentryRequestScopeSubscriber.php` — new (`final class`, EventSubscriber)
- `src/Shared/Service/AppLogger.php` — modified (убран hub + бэкдор)
- `tests/Unit/Shared/Infrastructure/Sentry/SentryMessengerScopeSubscriberTest.php` — new
- `tests/Unit/Shared/Infrastructure/Sentry/SentryRequestScopeSubscriberTest.php` — new
- `tests/Unit/Shared/Service/AppLoggerTest.php` — new
- `tests/Unit/MarketplaceAds/{FetchOzonAdStatisticsHandlerTest,ProcessAdRawDocumentHandlerTest}.php` — modified (AppLogger 1-арг)

### Self-review
- [x] Scope compliance — обогащение scope + чистка backdoor; продуктовая логика не тронута
- [x] Patterns / naming — `final class`, EventSubscriber, слой `Infrastructure`, constructor injection; переиспользован `AuditContextProvider` вместо дублирования гардов
- [x] Forbidden actions — none (нет миграций; `messenger.yaml`/`sentry.yaml` НЕ тронуты; нет новых зависимостей; не правил legacy `src/Service` сверх `AppLogger`, который уже Shared-сервис)
- [x] Security — user id/company_id — не PII; `send_default_pii:false` сохранён; email/ФИО не выставляются
- [x] CS — новые файлы чистые; `AppLogger.php` «Found 1» — преэкзистинг-долг в нетронутых методах `info()/error()` (точка в докблоке, `?\Throwable`, Yoda), намеренно не реформачу
- [x] PHPStan — N/A (не настроен)
- [x] Tests — 8 новых unit-тестов зелёные; полный unit-сьют **1200 OK**; `lint:container` OK
- [x] ARCHITECTURE.md — N/A (подписчики не публичный интерфейс; `AppLogger` указан без сигнатуры)

### Команды для проверки
```
docker compose run --rm -T site-php-cli php bin/phpunit --testsuite unit --filter 'SentryMessengerScopeSubscriber|SentryRequestScopeSubscriber|AppLoggerTest'
docker compose run --rm -T site-php-cli php bin/console lint:container --env=test
```

### Риски / на что обратить внимание ревьюеру
- `SentryRequestScopeSubscriber` на каждом main-запросе зовёт `AuditContextProvider::getCompanyId()` (читает сессию через `ActiveCompanyService`) — то же поведение, что и существующий `AuditLogSubscriber`, так что новый сайд-эффект не вводится.
- Теги обогащают события, отправляемые во время обработки (через Monolog→Sentry на текущем scope). Реальный эффект — по факту в GlitchTip после деплоя.
- `messenger.message` тег = FQCN (для анонимных классов в тестах — сгенерированное имя; в проде это реальные Message-классы).

### Открытые вопросы
- нет
