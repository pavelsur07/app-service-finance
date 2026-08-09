# Stage 2 Report — атомарный агрегат перевода и backend use case

Stage base commit: `e339b3dabdde1c8c893dc19e7bc0143699d08dac`

## Result

- Добавлена expand-only таблица `cash_transfer`, Doctrine entity и repository.
  Агрегат хранит company-scoped idempotency, ссылки на две уникальные ноги,
  точные nullable FX-метаданные и поля будущего soft-delete, не дублируя суммы
  и валюты транзакций.
- `FiatCurrency::canTransferTo()` задаёт единый v1-контракт: same-currency и
  `RUB↔USD/EUR`; `KZT` допускается только внутри одной валюты.
- `EffectiveExchangeRateCalculator` принимает обе фактические суммы,
  проверяет currency scale и положительность через `Money`, затем вычисляет
  quote per one base с BCMath и HALF_UP до scale 18. Float не используется.
- `CashFacade::createTransfer()` создаёт две технические операции в одной
  компании: `OUTFLOW/CF_TECH_OUT` и `INFLOW/CF_TECH_IN`, каждая с одной
  сбалансированной split-строкой и системной парой Project×ЦФО.
- Источник и назначение разрешаются company-scoped и должны быть разными
  активными не-crypto счетами, открытыми на дату операции. Закрытый финансовый
  период и отсутствующая обязательная системная категория блокируют запись.
- Агрегат, обе ноги, splits, аудит и пересчёт обоих счетов входят в одну
  DB-транзакцию. Порядок account locks детерминирован UUID; сбой пересчёта
  откатывает все записи.
- PostgreSQL advisory lock и unique `(company_id, idempotency_key)` обеспечивают
  race-safe replay. Повтор возвращает исходные IDs без VAT, PaymentPlan,
  auto-rule, balance или cache side effects; snapshot cache инвалидируется
  один раз после успешного commit.
- Проверены переводы между кассовым RUB-счётом и USD-кошельком, а также
  same-currency USD. Комиссия остаётся отдельной обычной исходящей операцией.
- `ARCHITECTURE.md` описывает агрегат, публичный facade contract, котировку,
  системные категории, idempotency replay и ограничения v1.

## Checks

- Stage baseline: 19 tests / 121 assertions — green.
- Transfer-focused final slice: 26 tests / 128 assertions — green.
- `tests/Unit/Cash`: 190 tests / 661 assertions — green.
- `tests/Integration/Cash`: 160 tests / 672 assertions — green.
- Cash + account functional slice: 59 tests / 415 assertions — green.
- Doctrine mapping validation (`--skip-sync`): green.
- Migration on isolated test DB: initial up exposed and fixed the actual
  `companies` table name; final down/up: green. Migration contains 13
  expand-only SQL statements on up and one table drop on explicit down.
- Doctrine schema dump after migration contains no `cash_transfer` drift;
  unrelated pre-existing project schema drift remains outside this Stage.
- PHP CS Fixer dry-run for changed PHP files: green.
- `git diff --check`: green.

## Reviews

- Internal independent complete-Stage review: green; no BLOCKER/IMPORTANT.
- First successful external review: `REVIEW_GREEN`. A safe MINOR was addressed
  by documenting that an idempotency key identifies the first accepted command
  and may only be replayed by the caller with the same payload.
- The reported FX-rule duplication was rejected: calculator and aggregate both
  delegate pair selection to `FiatCurrency::canTransferTo()`; the entity's
  independent metadata validation intentionally protects its invariant.
- Final external repeat review of the updated complete diff: `REVIEW_GREEN`.
- Accepted FOLLOW-UP for Stage 3: make transaction-to-transfer lookup explicitly
  company-scoped when lifecycle guards become production callers.
- Remaining BLOCKER/IMPORTANT findings: none.

## Production

No production or staging action was performed. The Production Gate remains
closed.
