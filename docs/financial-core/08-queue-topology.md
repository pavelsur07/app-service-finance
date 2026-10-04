# 08. Queue topology

**Решение Stage 0** (см. ADR-004). Меняется только Stage 3; существующие очереди
и маршрутизация в Stage 0 не затрагиваются.

## 1. Текущая (кратко)

`async_sync`, `async_pipeline`, `async_wb_finance`, `async_ads` + алиасы
`ingest_fetch`/`ingest_normalize` на тех же Redis-стримах; общий `failed`
(Doctrine). Подробно — `01 §3`.

## 2. Целевая

Ядро получает **собственные транспорты**, не смешиваясь с legacy-конвейерами:

| Транспорт | Producer | Consumer | Сообщение | Retry | DLQ | Порядок | Идемпотентность |
|---|---|---|---|---|---|---|---|
| `fincore_events` | Outbox Publisher | Posting Orchestrator | `PostEventMessage{eventId, companyId}` | 5 × 5 с, ×3, max 10 мин | `fincore_failed` | не требуется; зависимости через `source_revision`/`causation_id` | `UNIQUE(event_id, consumer)` |
| `fincore_projection` | Orchestrator (через outbox-строку `PostingGroupCreated`) | проекции P&L / Cash / AR-AP | `ProjectGroupMessage{groupId, companyId, ledger}` | 5 × 10 с, ×2 | `fincore_failed` | не требуется (проекция пересчитывается из проводок) | ключ агрегата, пересчёт, не дельта |
| `fincore_balance` | Orchestrator | Balance intake adapter | `IntakeBalanceMessage{groupId, companyId}` | 10 × 30 с, ×2, max 1 ч (внешний по отношению к ядру контур) | `fincore_failed` | не требуется | `request_key` Balance |
| `fincore_reconcile` | cron-команды | reconcile-проверки | `ReconcileCheckMessage{check, companyId, window}` | 2 × 60 с | `fincore_failed` | нет | read-only; результат — запись в `fincore_reconcile_findings` по ключу `(check, company, window)` |
| `fincore_failed` | Messenger (`failure_transport` на каждом из четырёх) | **человек/оператор**, `fincore:dlq:*` | — | — | — | — | — |

Принципы:

1. **Транспорт ≠ источник истины.** Источник — `fincore_outbox`/`fincore_events`.
   Потеря содержимого Redis не теряет события: sweeper перепубликует `pending`
   и события без processing. Это снимает R-02/R-03 для ядра независимо от того,
   Redis или Doctrine используется как брокер.
2. **Брокер ядра.** Решение: **Redis (отдельные стримы `fincore_*`)**, отдельный
   воркер на каждый транспорт; обоснование — единообразие с текущей
   инфраструктурой, потеря восстановима. Допустимая альтернатива (если выберем
   усиливать надёжность вместо sweeper'а): Doctrine-транспорт на том же
   подключении — отвергнута для событий, т.к. ack удаляет сообщение и теряется
   след (ADR-002).
3. **Изоляция.** Свои DSN (`MESSENGER_TRANSPORT_DSN_FINCORE_*`), свои воркеры,
   свои лимиты памяти; один сбойный consumer не блокирует legacy-очереди.
   Опционально отдельный Redis-инстанс (R-21) — отдельное решение Stage 3.
4. **Один тип сообщения — один транспорт** (CLAUDE.md «Message / Handler»);
   маршрутизация в `messenger.yaml` — HIGH-LOCAL.
5. **Сообщения несут только скаляры/ID**; payload события читается из БД.
6. **Tenant.** Сообщения реализуют `CompanyAwareMessage`; `CompanyFilterMiddleware`
   включает фильтр компании в воркере (сегодня — только 2 сообщения Ingestion, R-24).
7. **Ordering.** Не полагаемся на порядок; при необходимости горизонтального
   масштабирования consumer'ы безопасны (INV-15, `07 §4`).
8. **Алерты** (Stage 3): возраст старейшего `pending` в outbox > 5 мин;
   событие `published` без processing > 15 мин; глубина `fincore_failed` > 0 более
   10 мин; смерть воркера (heartbeat). Гейты по свежести, не по накопленному
   объёму (`docs/workflow/health-gates.md`).

## 3. Что делаем с существующими очередями

Не переименовываем и не объединяем. Legacy-сообщения продолжают ходить как
сейчас до cutover соответствующего потока. Маршрутизация новых сообщений —
отдельные записи; `ingest_fetch/normalize` остаются алиасами (разделение
воркеров — Stage 3, по желанию, отдельным решением Владельца). Нерутированное
`ScoreCompanyCounterpartiesMessage` не относится к ядру (фиксируется как R-24b
для Stage 1).

## 4. Конфигурационный набросок (для Stage 3, не применять сейчас)

```yaml
framework:
  messenger:
    transports:
      fincore_events:
        dsn: '%env(MESSENGER_TRANSPORT_DSN_FINCORE_EVENTS)%'
        failure_transport: fincore_failed
        retry_strategy: { max_retries: 5, delay: 5000, multiplier: 3, max_delay: 600000 }
      fincore_failed: 'doctrine://default?queue_name=fincore_failed'
    routing:
      'App\FinancialCore\Message\PostEventMessage': fincore_events
```
