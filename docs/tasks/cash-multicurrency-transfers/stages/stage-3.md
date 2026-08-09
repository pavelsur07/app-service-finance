# Stage 3 Report — lifecycle и currency-safe read models

Stage base commit: `9384ec7ec99ba0d1f921fa2e52bf8b8160cebce4`

## Result

- Добавлен атомарный lifecycle перевода через `CashFacade::deleteTransfer()` и
  `restoreTransfer()`: агрегат и обе ноги меняют состояние вместе под
  company-scoped pessimistic lock, с повторной проверкой состояния и периода.
- Lifecycle пишет явный аудит агрегата и обеих транзакций с actor, пересчитывает
  два счёта в стабильном UUID-порядке и инвалидирует dashboard snapshot один
  раз после успешного commit. Ошибка пересчёта доказанно откатывает изменения и
  аудит.
- Generic edit/delete/restore, manual split и bulk-delete не позволяют менять
  отдельную ногу агрегата. Legacy `isTransfer=true` без `cash_transfer`
  сохраняет прежнее поведение.
- ДДС-отчёт показывает `CF_TECH_OUT`/`CF_TECH_IN` в исходной валюте ноги:
  same-currency перевод даёт zero net, cross-currency — отдельные движения без
  пересчёта, soft-delete исключает обе ноги.
- Dashboard API принимает валидированную `currency` с default `RUB`, возвращает
  `context.cash_currency` и изолирует cache key по валюте. Currency включена в
  telemetry, warmup output и Cash drilldowns.
- Free cash, фонды, inflow/outflow, CAPEX, cashflow split и top-cash фильтруются
  до агрегации. Revenue/profit/top-P&L не зависят от Cash currency selector.
- Список и XLSX export используют общий валидированный currency filter;
  company scope, пагинация и unfiltered backward compatibility сохранены.
- `ARCHITECTURE.md` обновлён lifecycle, read-model, dashboard и facade
  контрактами.

## Checks

- Stage baseline: 26 tests / 133 assertions — green.
- Lifecycle/generic-guard focused slice: 52 tests / 267 assertions — green.
- Cashflow/transfer slice: 18 tests / 156 assertions — green.
- Dashboard/Analytics slice: 17 tests / 180 assertions — green.
- List/export slice after validation fix: 10 tests / 49 assertions — green.
- Final combined `Unit/Analytics + Unit/Cash + Integration/Cash +
  Functional/Analytics + Functional/Cash`: 439 tests / 1962 assertions — green.
- Doctrine mapping validation (`--skip-sync`): green.
- Analytics warmup `--help`: green; currency option defaults to RUB.
- Twig lint for transaction templates: 9 files, green.
- PHP CS Fixer dry-run for all 33 Stage-owned PHP files: green.
- `git diff --check`: green.
- Whole-repository CS dry-run remains red on 581 pre-existing unrelated files;
  no unrelated formatting was changed.

## Reviews

- Internal independent complete-Stage review: green; no BLOCKER/IMPORTANT.
- First external run exhausted its 40-turn limit before a verdict and was not
  counted as a review result.
- Completed external review: `REVIEW_GREEN`; no BLOCKER/IMPORTANT. Its safe
  currency-validation MINOR was fixed in list/export.
- Repeat external review: `REVIEW_GREEN`; repository `final` MINOR was resolved
  by moving the bulk membership lookup into the already injected
  `CashTransactionRepository`, without adding a new interface.
- Final complete-diff external repeat confirmed tenant-isolated DBAL lookup,
  bulk guard ordering and `CashTransferRepository` finality: `REVIEW_GREEN`.
- Remaining BLOCKER/IMPORTANT/MINOR findings: none.
- FOLLOW-UP: if a future caller uses `AutoRuleDispatchGuard::suppress()` while
  updating `CashTransaction`, it must retain the current explicit-audit
  convention; all existing callers were reviewed and are covered today.

## Production

No production or staging action was performed. The Production Gate remains
closed.
