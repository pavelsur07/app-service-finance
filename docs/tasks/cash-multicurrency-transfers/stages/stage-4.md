# Stage 4 Report — transfer UI and cash currency selector

Stage base commit: `3c9cd4cd53dc07dadf4a06f541f69e52ce28163f`

## Result

- Added the Symfony form and DTO for transfer creation. Account choices are
  active, fiat, non-crypto and company-scoped; amounts remain exact decimal
  strings and a hidden UUIDv7 key protects idempotent resubmission.
- Added tenant-scoped create/show/delete/restore/deleted-list routes and Twig
  templates. Financial validation, effective-rate calculation and atomic pair
  lifecycle remain inside `CashFacade`; the UI does not calculate FX values.
- The aggregate show page displays both exact legs, accounts, currencies,
  technical categories, effective quote and pair status. Its dedicated
  repository lookup eager-loads the displayed graph without changing generic
  persistence/lifecycle lookup semantics.
- Aggregate legs link to the transfer and no longer expose individual edit,
  split, delete, restore or bulk-selection actions. Standalone transactions and
  legacy `isTransfer=true` rows retain their existing behavior.
- Added the RUB/USD/EUR/KZT cash-currency selector to both Home modes and the
  existing React dashboard. The selection is persisted in the URL and passed
  to the dashboard API; Cash widgets use it while P&L widgets remain RUB.
- Server-rendered Home cash balances and inflow/outflow now filter before
  aggregation. The default view is intentionally RUB-only and counts active
  accounts only; this replaces the previous mixed-currency/all-account balance
  on bookmarked URLs without `currency`.
- No new dependency, Vite entrypoint or authoritative JavaScript financial
  logic was introduced.

## Checks

- Stage baseline backend slice: 25 tests / 231 assertions — green.
- Stage baseline frontend lint and production build — green; the existing
  missing `@symfony/ux-turbo/package.json` warning is unchanged.
- Complete bounded Cash/Analytics/Home run after review fixes: 438 tests /
  1978 assertions — green.
- Focused transfer controller/action/persistence repeat after separating the
  eager detail lookup: 19 tests / 179 assertions — green.
- Final functional repeat after cosmetic review fixes: 2 tests / 44 assertions
  — green.
- Doctrine mapping, test cache warmup, Twig lint, task-scoped PHP CS Fixer,
  frontend ESLint, container production build and `git diff --check` — green.
- A host Vite build initially encountered a pre-existing root-owned generated
  `.vite` directory; the supported `site-frontend` container build completed
  successfully without changing host permissions.

## Reviews

- Internal independent complete-Stage review: green after adding selected-
  currency KPI coverage and loading the existing dashboard Vite entry.
- Four mandatory 40-turn Claude runs exhausted their turn limit without a
  verdict. The owner authorized `--max-turns 120`; every other safe-mode and
  read-only restriction remained unchanged.
- First completed external pass: `REVIEW_GREEN`. Its valid MINOR exposed the
  filtered one-leg/select-all boundary; the row-ID comparison and regression
  test now cover it.
- Second completed pass: `REVIEW_GREEN`. Both safe MINORs were fixed by reusing
  the mapped ID list and adding a dedicated eager detail lookup. Focused tests
  caught and prevented applying the eager joins to the generic lookup.
- Third completed pass: `REVIEW_GREEN`. Two cosmetic MINORs were fixed by
  removing an unused frontend response field and normalizing test imports.
- Final complete-diff pass: `REVIEW_GREEN`; no BLOCKER or IMPORTANT findings.
- The remaining formatter MINOR was rejected for this Stage because the
  reviewer confirmed it predates the diff and belongs to excluded UI-Kit
  formatting work. Follow-ups are limited to dashboard UX/coverage polish and
  the pre-existing currency-format convention.

## Production

No production or staging action was performed. The Production Gate remains
closed.
