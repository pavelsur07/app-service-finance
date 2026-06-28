# Logging & Error Reporting Hardening — Plan (Phase 0)

> Бэкенд-задача (PHP/Symfony). Workflow — автономный режим из `CLAUDE.md`.
> Цель: система ловит все важные ошибки, без спама; info/diagnostic-логи остаются в файлах, в GlitchTip — только то, что требует внимания человека.
> **Изменений в коде ещё нет. Этот план ждёт одобрения Владельца (Phase 0 STOP).**

## Summary

- Стек: Symfony 7.4, `sentry/sentry-symfony ^5.10`, `symfony/monolog-bundle ^3.11`. «Sentry» физически = self-hosted **GlitchTip** (`SENTRY_DSN=…glitchtip.staging.vashfindir.ru/1`).
- Текущее состояние (исследовано, см. раздел «Baseline»): воронка ошибок единая (всё через Monolog, SDK-перехватчики выключены), в GlitchTip уходит строго `ERROR`, handlers в коде уже различают `warning` (ожидаемо/ретрай) и `error` (инцидент).
- Задача — **конфигурационная и конвенционная**, не продуктовая. Новых Entity/Repository/Action/Facade/Message/миграций **нет**. Меняются только `config/packages/*.yaml`, добавляется один stateless-сервис скраббинга и (опционально) middleware обогащения, плюс правки документации.
- Все правки `sentry.yaml` / `monolog.yaml` / `framework.yaml` / `messenger.yaml` — 🔴 **HIGH** (затрагивают поведение наблюдаемости и роутинг), поэтому каждый такой этап заканчивается STOP.

## Baseline (что есть сейчас — для ревьюера)

- `config/packages/sentry.yaml`: `register_error_listener:false`, `register_error_handler:false`; handler `Sentry\Monolog\Handler` на `level: error`, `fillExtraContext: true`. Опций `environment`/`release`/`before_send`/`ignore_exceptions` нет.
- `config/packages/monolog.yaml`: prod — `main` (`fingers_crossed`, `action_level:error`, `excluded_http_codes:[404,405]`, `buffer_size:50`) → `nested` (stderr JSON); отдельный top-level `sentry` (`error`, кроме канала `marketplace_ads`); `marketplace_ads` → rotating_file (вне GlitchTip); `deprecation` → stderr. Каналы: `deprecation, import.bank1c, recalc, marketplace_ads, legacy_wb_sync`.
- `src/Shared/Service/AppLogger.php`: `info/warning` → файл; `error` → Monolog → GlitchTip; `logSlowExecution` → **прямой** `sentryHub->captureMessage(warning)` (обход правила «только error»).
- Дисциплина вызовов: info 244 / warning 173 / error 165 / debug 5. Эталон — `SyncWbFinancialReportDayHandler` (warning+Recoverable на ретраях, error+Unrecoverable на инцидентах). Messenger: ретраи + `failure_transport: failed`.
- `config/packages/framework.yaml`: блок `exceptions` **не настроен** → дефолтный `ErrorListener` логирует 4xx `HttpException` на уровне `ERROR`.

## Проблемы, которые закрываем

1. **4xx → GlitchTip.** Дефолт Symfony 7.4: `HttpException` <500 логируется как `ERROR`; `excluded_http_codes` влияет только на `fingers_crossed`, а не на отдельный `sentry`-handler. Главный источник потенциального спама.
2. **Нет скраббинга / `before_send`.** `fillExtraContext:true` шлёт context-массивы — риск утечки токенов/PII в GlitchTip (запрещено `CLAUDE.md`).
3. **Нет `environment`/`release`/`server_name`.** Не отделить staging от prod, не привязать регрессию к релизу.
4. **Бёрсты ошибок.** Циклы/массовые импорты могут залить одинаковыми событиями.
5. **`logSlowExecution` — бэкдор** мимо инварианта «в GlitchTip только error».
6. **Слепые зоны:** `marketplace_ads` полностью вне GlitchTip; `legacy_wb_sync`-карантин — подтвердить актуальность.
7. **Нет глобального обогащения** событий (`company_id`/`user_id`/`message_class`) — триаж тяжелее.

## Implementation Stages

- **Stage 0 — Reconnaissance (no code).** Подтвердить на dev/staging фактическое поведение: какие коды реально долетают в GlitchTip (особенно 404/403/422), есть ли prod-DSN отдельно от staging, активен ли ещё `legacy_wb_sync`-карантин и нужен ли `marketplace_ads` в алертах. Результат — короткий отчёт `docs/tasks/logging/stages/stage-0.md`. **Risk 🟢 LOW** → continue.

- **Stage 1 — Уровни логов для HTTP-ошибок.** В `config/packages/framework.yaml` задать `framework.exceptions` log_level: 4xx `HttpException` → `warning`, `NotFoundHttpException`/`AccessDeniedException` → `info`/`notice`. Цель — 4xx перестают попадать в `sentry`-handler в корне. Изменение поведения наблюдаемости (legacy-зона конфигов) → **Risk 🔴 HIGH** → STOP.

- **Stage 2 — `sentry.yaml`: окружение, релиз, ignore.** Добавить `environment: '%env(APP_ENV)%'`, `release: '%env(default::SENTRY_RELEASE)%'`, `server_name`, `send_default_pii: false`, `max_request_body_size: small`, `ignore_exceptions` (ожидаемые доменные/HTTP-исключения, которые не инцидент). DSN/проекты staging↔prod — по итогам Stage 0. **Risk 🔴 HIGH** → STOP.

- **Stage 3 — `before_send` скраббинг (новый stateless-сервис).** `src/Shared/Sentry/EventScrubber` (`final readonly class`, `__invoke(Event,?EventHint): ?Event`): вычистить из `extra`/`request`/`tags` ключи паролей/токенов/`Authorization`/ИНН/PII; опционально дропать остаточный шум. Подключить через `before_send`. **Risk 🔴 HIGH** (меняет контракт того, что уходит во внешний сервис) → STOP.

- **Stage 4 — Антиспам бёрстов.** Перед `sentry`-handler поставить `DeduplicationHandler` (схлопывание одинаковых ошибок за окно) ИЛИ rate-limit в `before_send` по fingerprint. Плюс конвенция для batch/циклов: один `error` с `count` + пример вместо ошибки на запись. **Risk 🔴 HIGH** (правка `monolog.yaml`) → STOP.

- **Stage 5 — Глобальное обогащение событий (опционально, по решению Владельца).** Messenger middleware + kernel listener, выставляющие в Sentry scope `company_id`/`user_id`/`message_class` вместо ручного `context` в каждом handler. Привести `AppLogger::logSlowExecution` к общему правилу (warning остаётся в файле либо осознанно помечается как метрика, без обхода «только error»). **Risk 🔴 HIGH** → STOP.

- **Stage 6 — Конвенция + аудит handler'ов.** Зафиксировать правило `error = нужен человек / warning = ожидаемо-ретрай` в `PATTERNS.md` (раздел «Логирование») и `CLAUDE.md`. Проаудитить ~44 MessageHandler по образцу `SyncWbFinancialReportDayHandler`; точечные правки уровней — отдельными мелкими этапами, **каждый со своим self-review**. Документ — 🟢 LOW; точечная правка уровня в legacy-коде — оценивать индивидуально.

- **Stage Final — Handoff.** `make stan && make cs && make test`, сверка «Глобальных запретов», `docs/tasks/logging/handoff.md` (список изменённых конфигов/контрактов наблюдаемости, риски, follow-ups). **STOP — Final Owner review.**

## Карта изменений

| Файл | Тип | Этап |
|---|---|---|
| `config/packages/framework.yaml` | modified (блок `exceptions`) | 1 |
| `config/packages/sentry.yaml` | modified (options) | 2 |
| `src/Shared/Sentry/EventScrubber.php` | new (`final readonly class`) | 3 |
| `config/packages/services.yaml` (или sentry.yaml `services`) | modified (регистрация `before_send`) | 3 |
| `config/packages/monolog.yaml` | modified (DeduplicationHandler) | 4 |
| `src/Shared/Messenger/*Middleware`, kernel listener | new (опционально) | 5 |
| `src/Shared/Service/AppLogger.php` | modified (logSlowExecution) | 5 |
| `PATTERNS.md`, `CLAUDE.md` | modified (конвенция логирования) | 6 |
| `ARCHITECTURE.md` | modified, если появится новый сервис/middleware | 3 / 5 |
| **Миграции БД** | **нет** | — |
| **Изменения публичного HTTP-API** | **нет** (4xx-ответы клиентам не меняются; меняется только log_level) | — |

## Test Plan

- Stage 1: functional-тест — запрос на несуществующий роут (404) и на защищённый (403) **не создаёт ERROR-запись** для `sentry`-handler; 5xx — создаёт. Проверка через тестовый Monolog handler / `TestLogger`.
- Stage 3: unit-тесты `EventScrubber` — токен/пароль/`Authorization`/ИНН вычищаются из `extra`/`request`; обычные поля остаются; `null`-вход не падает.
- Stage 4: unit/integration — N одинаковых ошибок в окне → одно событие (DeduplicationHandler) либо подтверждение rate-limit в `before_send`.
- Stage 5: тест middleware — scope содержит `company_id`/`message_class` при обработке сообщения; отсутствие утечки между сообщениями.
- Каждый этап: `make stan` чисто на изменённом коде, `make cs`, релевантный `make test`.

## Обязательные STOP

- Stage 1/2/4 — правка `framework.yaml`/`sentry.yaml`/`monolog.yaml` (наблюдаемость) — STOP перед PR.
- Stage 3/5 — изменение контракта отправляемых во внешний сервис данных — STOP.
- Решение staging↔prod GlitchTip (разные проекты/окружения) — STOP, решает Владелец.
- Любая правка уровня логирования в legacy-зоне (`src/Service`, `src/Controller`) на Stage 6 — STOP.

## Assumptions & Guardrails

- Не трогаем продуктовую логику и Entity; задача только про наблюдаемость.
- `traces_sample_rate` оставляем неустановленным (performance-транзакции не включаем) — по умолчанию шума/нагрузки нет; включение перфоманса — отдельная задача.
- `marketplace_ads` и `legacy_wb_sync` менять только после явного решения на Stage 0 (сейчас это осознанные слепые зоны/карантин).
- Существующие незакоммиченные `docs/tasks/*` и `.mimocode/` не трогаем.
- Никаких прогонов на staging/prod, никаких миграций, merge — только PR после одобрения.

---

🛑 **Phase 0 STOP. Жду одобрения плана Владельцем. Без подтверждения код не пишу.**

Открытые вопросы к Владельцу:
1. GlitchTip staging и prod — один проект (id=1) или разводим? (влияет на Stage 2)
2. `legacy_wb_sync`-карантин и `marketplace_ads`-blind-spot — оставляем как есть или поднимаем в алерты? (Stage 0/6)
3. Stage 5 (глобальное обогащение через middleware) — делаем сейчас или выносим в follow-up?
4. Нужен ли минимальный `traces_sample_rate` (например 0.05) для перфоманса, или performance не включаем вовсе?
