# Handoff — Logging & Error Reporting Hardening

**Задача:** настроить логирование так, чтобы все важные ошибки попадали в GlitchTip без спама, а info/диагностика оставались в файлах.
**Ветка:** `master` (коммиты ниже). **Статус:** все запланированные этапы закрыты, Stage 5 вынесен в follow-up по решению Владельца.
**Финальный STOP:** ждёт ревью Владельца перед мержем/деплоем.

## Сводка по этапам

| Stage | Риск | Суть | Коммит |
|---|---|---|---|
| 0 Recon | 🟢 | Разведка: подтвердил 4xx→ERROR, prod-DSN из секрета, legacy_wb_sync-карантин, ads blind spot | `07c8e46f` |
| 1 4xx levels | 🔴 | `framework.exceptions`: конкретные 4xx-подклассы → notice/warning (не в GlitchTip); 5xx/база не тронуты | `4f5f0183` |
| 2 Sentry tags | 🔴 | `environment`/`release`(git sha)/`send_default_pii:false`/`max_request_body_size:small` | `f3b43e1e` |
| 3 Scrubber | 🔴 | `EventScrubber` (before_send): рекурсивная чистка секретов/PII из extra/tags/request | `97ee04ff` |
| 4 Rate-limit | 🔴 | `SentryRateLimiter`+`SentryBeforeSend`: троттлинг идентичных бёрстов (первые N/окно проходят) | `58c0b2ff` |
| 6 Convention | 🟢 | PATTERNS.md §23 + правило в CLAUDE.md; аудит handler'ов (мислейблы → follow-up) | `88bdd753` |

Эффект на цель: 4xx больше не шумят (Stage 1), события тегированы и без PII (Stage 2–3), бёрсты ограничены (Stage 4), команда имеет конвенцию (Stage 6).

## Изменённые публичные контракты / наблюдаемость

- **HTTP-API клиентам — без изменений.** Меняется только `log_level` исключений, не статус-коды и не тела ответов.
- **Контракт наблюдаемости (что уходит в GlitchTip) изменён намеренно:**
  - 4xx больше не создают ERROR-события.
  - События тегируются `environment`/`release`.
  - Секреты/PII вычищаются `before_send`.
  - Идентичные повторы сверх порога (10/60с на ключ, per-process) дропаются.

## Миграции БД
**Нет.** Задача конфигурационная/инфраструктурная.

## Новые зависимости
**Нет.** Использованы уже установленные `sentry/sentry-symfony`, `psr/clock`, `symfony/clock`, `monolog/monolog`.

## Проверки (выполнены в docker)

| Проверка | Результат |
|---|---|
| Unit suite (полный) | ✅ 1187 tests OK (вкл. 11 новых Sentry; 1 warning + 1 deprecation — преэкзистинг) |
| Functional `ExceptionLogLevelTest` | ✅ OK (зелёный с фиксом, красный без — регрессия доказана) |
| `lint:container --env=test` | ✅ OK (before_send/clock резолвятся) |
| `lint:yaml` (framework/sentry/test-monolog) | ✅ OK |
| CS-fixer на всех 7 изменённых PHP | ✅ `Found 0 of 1` каждый |
| PHPStan | N/A — в проекте не настроен (нет зависимости/таргета) |
| Integration suite | ⚠️ НЕ запускался в этой среде (нужен DB-prep, тяжёлый; есть преэкзистинг-состояние). **Рекомендация: прогнать в CI.** |

## Сверка «Глобальных запретов» (CLAUDE.md)
- ✅ Нет `dump()`/`dd()`/`var_dump()`
- ✅ Нет `new SomeService()` в проде — только constructor injection (`SentryBeforeSend`)
- ✅ Нет `flush()` в Repository (N/A)
- ✅ Нет хардкода секретов/URL/ключей (`api_key` в `EventScrubber` — это паттерн-ключ редактирования, не секрет; URL только в тестах)
- ✅ Нет импорта Service/Repository чужого модуля (импорты — только Sentry SDK / psr-clock)
- ✅ Нет миграций без STOP (миграций нет)
- ✅ Нет изменения публичного API
- ✅ Нет merge/force-push; каждый этап — отдельный self-review + Stage Report
- ✅ Размещение — слой `Infrastructure` (раздел «Структура файлов»)

## 🔴 Требуется действие Владельца (prod-infra, НЕ входит в мои правки)
Чтобы `release` заполнялся в GlitchTip, деплой должен прокидывать git sha:
1. В `docker-compose.prod.yml` рядом с `SENTRY_DSN` (оба сервиса) добавить:
   ```yaml
   SENTRY_RELEASE: ${SENTRY_RELEASE:-}
   ```
2. В пайплайне деплоя перед `up`:
   ```sh
   export SENTRY_RELEASE=$(git rev-parse HEAD)
   ```
`${…:-}` гарантирует безопасность при отсутствии переменной (релиз-трекинг просто выключен).

## Follow-ups (сознательно вне scope)
1. ⭐ **Downgrade `error→warning`** в `CloseMonthStageHandler:50` и `SyncOzonReportHandler:96` (+ регрессионный тест) — реальные ложные алерты в GlitchTip. Самый ценный follow-up.
2. Downgrade transient-`error` в `FetchOzonAdStatisticsHandler:210`, `AdBatchSchedulerCommand:185` (ads файловый — косметика).
3. Решение по `Ingestion/SyncJobFailureSubscriber:70` (warning→error?).
4. **Ads observability** (Stage 0 Q4): поднять terminal-сбои ads в GlitchTip после чистки уровней — отложено (ads остаётся файловым).
5. **Stage 5**: глобальное обогащение Sentry-scope (`company_id`/`user_id`/`message_class`) через Messenger-middleware + kernel listener; привести `AppLogger::logSlowExecution` к правилу «в GlitchTip только error».
6. Опционально: тюнинг порогов rate-limiter через `services.yaml` bind; глобальный (Redis) троттлинг, если per-process окажется мало.

## Риски / на что смотреть после деплоя
- Проверить в GlitchTip: 4xx исчезли из ошибок; новые события имеют `environment`/`release`; в `extra`/`request` нет секретов; бёрсты схлопнуты.
- `before_send` чистит по **ключам**, не по содержимому значений — секрет «голой строкой» в тексте сообщения не вычистится (возможна regex-итерация).
- Rate-limit per-process: при множестве воркеров итоговый поток ≈ limit × число процессов за окно.

🛑 **Final Owner review. Merge — только после одобрения.**
