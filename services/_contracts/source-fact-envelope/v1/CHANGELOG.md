# source-fact-envelope — CHANGELOG

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
