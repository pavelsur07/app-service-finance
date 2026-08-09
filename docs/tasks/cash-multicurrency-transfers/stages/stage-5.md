# Stage 5 Report — verifier, documentation and Final Release Gate

Stage base commit: `0e646cb42cf971180d7d9107f0983ee48490df62`

## Result

- Added read-only `app:cash:verify-transfers`. Detailed checks process companies
  in batches of 100; two intentional global aggregate scans detect duplicate
  idempotency keys and cross-role reuse without hiding corruption at a batch
  boundary.
- The verifier covers aggregate/leg/account company scope, account type and
  opening date, directions, dates, supported fiat pairs, exact technical
  splits, same-currency equality, FX metadata/rate, lifecycle parity,
  idempotency and leg ownership.
- Legacy `isTransfer=true` transactions without an aggregate are reported as
  INFO. Diagnostics expose only check names and aggregate counts; the command
  has no repair or execute mode and performs DBAL reads only.
- Updated `ARCHITECTURE.md` and the Cash README with aggregate and facade
  contracts, v1 restrictions, verification, expand-first rollout and explicit
  Production Gate boundaries.
- Normalized transfer amount strings once at the application boundary so
  direct facade callers and UI callers persist exactly the amounts used for FX
  calculation. Spaced comma-decimal input has integration coverage.

## Checks

- Complete backend suite: 3221 tests / 17687 assertions — green.
- Final focused action/verifier repeat: 20 tests / 147 assertions — green.
- Twig lint: all 225 templates — green.
- Doctrine mapping validation — green. Full schema validation reports only
  pre-existing unrelated DB drift; schema dump contains no unexpected
  `cash_transfer` SQL.
- Task-owned PHP CS Fixer scope (79 files) and final changed-file repeat —
  green. Whole-repository CS remains red on 576 pre-existing unrelated files.
- Frontend ESLint and production build — green; the existing missing
  `@symfony/ux-turbo/package.json` warning is unchanged.
- Whole-repository UI-Kit checks remain red on pre-existing debt: 9194 legacy
  class usages versus the task-base 9086 (new Twig screens follow neighboring
  legacy Bootstrap patterns), and the unchanged 47 missing React wrappers.
- Direct test-environment verifier run, command help and `git diff --check` —
  green. Stage 2 isolated migration down/up and SQL review remain green; the
  migration has not changed since that Stage.

## Reviews

- Internal independent Stage 5 review: green after extending account checks to
  supported account types, existing rows and opening dates.
- Initial external Stage 5 review found a confirmed FX verifier BLOCKER:
  PostgreSQL division of `NUMERIC(18,2)` could lose precision for rates above
  one. Casting the numerator to `NUMERIC(38,19)` before scale-18 HALF_UP
  rounding fixed it; a non-round USD→RUB regression verifies the case.
- Completed Stage 5 review repeats ended `REVIEW_GREEN`; the documented global
  uniqueness scans resolved the only remaining batching MINOR.
- Final internal review of the complete task diff from exact base
  `1b77472f66085752ed3dffd78e3a4f6ccbc9162b` is green.
- First final external full-task review ended `REVIEW_GREEN` and identified one
  safe MINOR in comma-decimal normalization. The action-boundary fix and
  facade-level regression were added; the repeated full-task review confirmed
  the fix and ended `REVIEW_GREEN` with no new findings.
- Accepted FOLLOW-UP items are dashboard selector UX without a full reload and
  a possible future DB-level guard against cross-role leg reuse. Current write
  paths create fresh legs and the verifier detects the latter corruption.

## Production

No production or staging action was performed. The Draft PR remains Draft;
Ready, merge, release, migration and deploy require explicit owner decisions.
The Production Gate remains closed.
