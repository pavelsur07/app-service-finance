## Current checkpoint

**Phase:** Stage 1 — модуль BankSync и граница с Cash
**Status:** done — handoff
**Stage base commit:** `23491e8cf`

### Completed
- `plan.md` — задача, решения, этапы 1–13 (пересобраны 2026-10-26), поставки A/B/C.
- `alfa-letter.md` — письмо в поддержку Alfa API (черновик).
- `stages/stage-1.md` — подробный план Stage 1.
- 1.1 прод read-only: старые банковские таблицы и очереди пусты, ключ `v1` работает.
- 1.2 baseline: 99 тестов банковского импорта и изоляции — OK.
- 1.3–1.5 `31956ba5f`: каркас, адаптер Альфы (`/api`, `X-TraceId`), маппер.
- 1.6 `febafcc38`: `CashFacade` — импорт дня с пересчётом остатков, журнал, блокировка.
- 1.7 `ab7fa1efb`: сущности, кодек токена, миграция `Version20261026120000`.
- 1.8 `0f3953c90`: сообщение, handler, `bank-sync:enqueue`.
- 1.9–1.10: UI (5 контроллеров, форма на DTO), удаление старого кода Cash, baseline,
  правило границы, `ARCHITECTURE.md` 2.69, README Cash.

### Checks and baseline
- Локальные тесты: БД `app_test_banksync` в контейнере `asf1-pg`, сеть `asf1-net`
  (стек `app-service-finance` остановлен).
- Миграция up → down → up локально — OK.
- Unit-набор 4005 — OK; integration + functional 3199 — OK; PHPStan полный — 0 ошибок.

### Review status
- internal: done (найден и исправлен курсор нового счёта)
- external: раунд 1 — 1 IMPORTANT (миграция, исправлено `0a023ee6e`); раунд 2 — REVIEW_GREEN

### Exact next action
- Handoff Stage 1: push, PR, вопрос «merge and deploy» (с dispatch миграции).
  Затем Stage 2 (ротация ключа) — план в `plan.md` §5.

### Files to inspect first on resume
- `docs/tasks/bank-sync/stages/stage-1.md`
- `site/src/BankSync/MessageHandler/SyncBankConnectionHandler.php`
- `site/src/Cash/Application/ImportBankStatementDayAction.php`
