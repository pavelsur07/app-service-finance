# source-fact-envelope — CHANGELOG

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
- **Идемпотентность доставки:** заголовок `Idempotency-Key: <batch_id>`;
  ACK только после долговечной записи inbox; повтор возвращает прежний
  результат (семантика на приёмной стороне, `services/AGENTS.md` §3).
- **Коррекции источника:** новая `source_revision`; overwrite запрещён.
- **Идемпотентность строк:** семантика `source_key` за провайдером —
  зафиксирована в README контракта и не меняется без новой версии.
