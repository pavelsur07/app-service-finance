# Stage 1 Report — безопасный валютный фундамент Cash

Stage base commit: `1b77472f66085752ed3dffd78e3a4f6ccbc9162b`

## Result

- Добавлен единый fiat-контракт `RUB/USD/EUR/KZT`; формы, DTO, обычные
  transaction write-paths и импорты используют его вместо локальных списков.
- Валюта обычного счёта нормализуется при создании и защищена от изменения
  после сохранения. Криптокошельки сохранены вне fiat-контракта для обратной
  совместимости.
- Счёт, контрагент, статья ДДС и проект разрешаются в рамках компании; UUID
  другой компании больше не превращается в непроверенный Doctrine reference.
- Валюта транзакции является производной от счёта. Смена счёта обновляет её
  одновременно; mismatch и неподдерживаемые валюты отклоняются.
- File, 1C и provider bank imports проверяют company/currency boundaries до
  записи. Невалидная строка provider import учитывается как error, а не как
  duplicate.
- Legacy `PaymentPlan` без валюты автоматически сопоставляется только с RUB;
  существующая сохранённая связь остаётся читаемой.
- `ARCHITECTURE.md` описывает новый контракт. Миграций, transfer aggregate,
  dashboard selector и production-действий в Stage 1 нет.

## Checks

- Task-base baseline: 8 tests / 122 assertions — green.
- Интегральный Stage slice до review: 54 tests / 295 assertions — green.
- `tests/Unit/Cash`: 179 tests / 614 assertions — green.
- `tests/Integration/Cash`: 148 tests / 598 assertions — green.
- Cash + account functional slice: 59 tests / 415 assertions — green.
- Финальный targeted slice после review fixes: 61 tests / 325 assertions —
  green.
- Targeted UUID-guard repeat: 16 tests / 44 assertions — green.
- Doctrine mapping validation (`--skip-sync`): green.
- PHP CS Fixer dry-run по 28 изменённым PHP-файлам: green.
- `git diff --check`: green.

## Reviews

- Internal independent review: green after two confirmed fixes:
  - fiat validation no longer narrows the out-of-scope crypto-wallet contract;
  - bank-import duplicate and invalid-currency counters are separated, and
    incoming currency whitespace is normalized.
- First external review: `REVIEW_GREEN`, no BLOCKER/IMPORTANT. One safe MINOR
  was fixed by adding repository UUID guards consistent with the existing
  company-scoped category lookup.
- Repeat external review of the complete updated Stage diff: `REVIEW_GREEN`.
- Lifecycle callback note was classified FOLLOW-UP: keeping the Doctrine
  guard is currently the only global protection for all writers; moving it
  only to the service would weaken the invariant, while rejecting changes in
  the setter would break the current entity-bound create form.
- Remaining BLOCKER/IMPORTANT findings: none.

## Production

No production or staging action was performed. The Production Gate remains
closed.
