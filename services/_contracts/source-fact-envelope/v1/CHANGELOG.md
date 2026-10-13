# source-fact-envelope — CHANGELOG

## Доставка (2026-10-13, до утверждения)

Транспорт пересмотрен владельцем: вместо push (`POST` в inbox Symfony) —
**pull-модель** (Symfony забирает батчи `GET`-запросом, ack отдельным
вызовом). Схема envelope не изменилась; семантика — в README §«Доставка»;
статусная машина батчей `new → acked` — `services/AGENTS.md` §9.

## v1.0.0-draft (2026-10-13) — первый проект

Проект контракта на базе `docs/architecture/marketplace-audit/05-target-architecture.md`
(§«Контракт handoff»). Статус «проект»: до реализации Symfony inbox endpoint
согласовать с `docs/financial-core/05-financial-events.md`, `07-idempotency.md`,
ADR-006/008 и natural keys Ingestion. После утверждения — изменения только через v2.

Принятые решения:

- **Деньги:** точные десятичные строки (pattern в schema) + `currency`
  ISO 4217. Minor units для v1 отклонены — одна форма, меньше мест для
  рассинхрона округлений.
- **Полнота батча:** `batch.status` = `complete` | `partial`, `cursor` (null
  при complete), `page_count`.
- **Коррекции источника:** новая `source_revision`; overwrite запрещён.
- **Идемпотентность строк:** семантика `source_key` за провайдером —
  зафиксирована в README контракта и не меняется без новой версии.
