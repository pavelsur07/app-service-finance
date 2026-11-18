# source-fact-envelope — CHANGELOG

## v1.4.0 (2026-11-18) — новые ресурсы воронки, форма не менялась

- `ozon-fetcher` публикует `ozon.analytics.funnel`: запись = строка SKU дня как в ответе Ozon
  (`{"dimensions","metrics"}`, метрики по порядку запроса сервиса), ключ `ozon:analytics.funnel:{sku}`.
  День, который Ozon не отдал по тарифу кабинета, — одна запись `ozon:analytics.funnel:unavailable`.
- `wb-fetcher` публикует `wb.analytics.funnel`: запись = воронка карточки за день
  (`{"product":{"nmId"},"statistic":{"selected"}}`, остальные поля карточки не передаются),
  ключ `wb:analytics.funnel:{nmId}`.
- Схема envelope не менялась.

## v1.3.0 (2026-11-15) — новый ресурс, форма не менялась

- `wb.ads.daily` (wb-fetcher): реклама WB за день, запись — кампания (списания `upd` и её
  строка `fullstats`), ключ — `advertId` (задача `marketplace-profit-pipeline`, этап 8.4).

## v1.2.0 (2026-11-14) — новый ресурс, форма не менялась

- `wb.finance.sales-report.daily` (wb-fetcher): дневной отчёт о продажах WB,
  запись — строка отчёта как есть, ключ — `rrdId` (задача
  `marketplace-profit-pipeline`, этап 8.1). Стабильность `rrdId` между
  перечитываниями дня проверяется на проде; если не подтвердится — ключ от
  содержимого (v1.3). `.weekly` не публикуется.

## v1.1.0 (2026-11-14) — новый ресурс, форма не менялась

- `ozon.ads.statistics` (ozon-fetcher): дневной отчёт статистики Performance,
  запись — строка «кампания × SKU» как в CSV. Расход по SKU для канона
  (задача `marketplace-profit-pipeline`, этап 6). Хвост `ozon.ads.statistics.tail`
  не публикуется.

## v1.0.0 (2026-11-13) — утверждён, действует

Решение Владельца 13.11.2026 (задача `docs/tasks/marketplace-profit-pipeline`,
этап 2). Проект переработан до утверждения; дальше изменения — только через v2.

- **Факт — запись источника целиком** (`payload` — исходный JSON), а не
  разложенная сумма. Прежний проект требовал повторить в сервисе разбор
  начислений из Symfony и держать два разбора в паритете; в записи не было
  SKU и отправления. Разбор теперь один — в переводчике канала Symfony.
  Убраны поля `operation_type`, `category_code`, `amount`, `currency`,
  `quantity`, `occurred_at`, `source_revision`, `storage_uri` и др.
- **Пачка — ревизия периода кабинета** целиком (`revision`, `content_hash`),
  а не произвольный батч строк. Коррекция источника — новая ревизия периода.
- **Три вызова:** список пачек (метаданные), страница записей, подтверждение.
  Неподтверждаемая пачка остаётся в списке — карантин на стороне Symfony.
- `source_key` для by-day — `ozon:accrual-by-day:{accrual_id}` (уровень
  начисления; компонентные ключи — дело переводчика).
- В БД сервиса — только указатель на страницы ревизии, данных источника нет.
- Первый ресурс — `ozon.finance.accrual-by-day` (ozon-fetcher).

## Правка проекта (2026-10-17, до утверждения)

- Ozon снял `/v3/finance/transaction/list` 09.09.2026: ресурс
  `ozon.finance.transaction-list` заменён на `ozon.finance.accrual-by-day`.
- `source_key` для by-day — `ozon:accrual-by-day:{accrual_id}:{component}`
  (без состояния, паритет с Ingestion). Прежний проект опирался на ключи
  Marketplace, которые зависят от состояния БД Symfony и producer'у недоступны.
- Схема полей не менялась.

## Доставка (2026-10-13, до утверждения)

Транспорт пересмотрен владельцем: вместо push (`POST` в inbox Symfony) —
**pull-модель** (Symfony забирает батчи `GET`-запросом, ack отдельным
вызовом). Схема envelope не изменилась; семантика — в README §«Доставка»;
статусная машина батчей `new → acked` — `services/AGENTS.md` §9.

## v1.0.0-draft (2026-10-13) — первый проект

Проект контракта на базе marketplace-аудита, 05-target-architecture.md
(§«Контракт handoff»). Статус «проект»: до реализации Symfony inbox endpoint
согласовать с финансовой моделью Financial Core (05-financial-events, 07-idempotency,
ADR-006/008; удалены из docs 22.10.2026) и natural keys Ingestion. После утверждения — изменения только через v2.

Принятые решения:

- **Деньги:** точные десятичные строки (pattern в schema) + `currency`
  ISO 4217. Minor units для v1 отклонены — одна форма, меньше мест для
  рассинхрона округлений.
- **Полнота батча:** `batch.status` = `complete` | `partial`, `cursor` (null
  при complete), `page_count`.
- **Коррекции источника:** новая `source_revision`; overwrite запрещён.
- **Идемпотентность строк:** семантика `source_key` за провайдером —
  зафиксирована в README контракта и не меняется без новой версии.
