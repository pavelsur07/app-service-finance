# wb-finance-quota — CHANGELOG

## v1.0.0 — 2026-11-02

- `POST /internal/wb-finance/sales-reports/slot` и `…/rate-limited`: слот и 429 в бакете
  `connection:<id>` лимитера Symfony `limiter.wb_finance` (задача wb-fetcher-finance, этап 5,
  замечание внешнего ревью: минуты :10/:20 не защищали общий лимит продавца).
