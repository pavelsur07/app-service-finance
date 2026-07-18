### Stage 7.7.1: Finance scalar mapping and pair validation — DONE

**Risk:** HIGH-LOCAL
**Next action:** Draft PR ready; Stage 7.7.2 remains a separate writer-propagation unit

#### What was done

- Mapped the already deployed nullable `responsibility_center_id` columns on Finance `Document`, `DocumentOperation`, and `PLDailyTotal`.
- Added scalar getters/setters only; no writer behavior was changed.
- Added `FinanceResponsibilityCenterPairValidator`, reusing `FinancialResponsibilityCenterFacade` for Company-owned active allowed pair validation.
- Added integration coverage for Finance scalar mapping round-trip and pair validation.
- Updated architecture documentation.

#### Files changed

- `site/src/Finance/Entity/Document.php` — mapped nullable scalar ЦФО id.
- `site/src/Finance/Entity/DocumentOperation.php` — mapped nullable scalar ЦФО id.
- `site/src/Finance/Entity/PLDailyTotal.php` — mapped nullable scalar ЦФО id.
- `site/src/Finance/Application/Service/FinanceResponsibilityCenterPairValidator.php` — new minimal validator over the Company facade.
- `site/tests/Integration/Finance/FinanceResponsibilityCenterPairValidatorTest.php` — new mapping and validation coverage.
- `ARCHITECTURE.md` — Stage 7.7.1 contract.

#### Definition of Done

- [x] Entity mapping validates against the already deployed Stage 7.5 schema.
- [x] Same-company active allowed pair passes.
- [x] Cross-company, archived, malformed, missing, and disallowed pairs fail closed.
- [x] `NULL` ЦФО remains allowed for legacy documents and totals.
- [x] No writer behavior changes.

#### Baseline

- `make site-test-integration` — passed before implementation: 730 tests, 3557 assertions. The intended filtered Makefile run executed the full integration suite because this target does not consume `FILTER`.

#### Checks

- targeted: `docker compose run --rm -T site-php-cli php bin/phpunit tests/Integration/Finance/FinanceResponsibilityCenterPairValidatorTest.php tests/Integration/Finance/ResponsibilityCenterFactSchemaTest.php` — passed: 5 tests, 62 assertions.
- module: `docker compose run --rm -T site-php-cli php bin/phpunit tests/Integration/Finance` — passed: 31 tests, 156 assertions.
- unit: `make site-test-unit` — passed: 1516 tests, 8912 assertions.
- full relevant stage: `make site-test-integration` — passed: 733 tests, 3571 assertions.
- mapping: `docker compose run --rm -T site-php-cli php bin/console doctrine:schema:validate --skip-sync --env=test` — passed; mapping OK, DB sync skipped.
- container: `docker compose run --rm -T site-php-cli php bin/console lint:container --env=test` — passed.
- coding style: `docker compose run --rm -T site-php-cli vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.php ...` — passed.
- diff hygiene: `git -C site diff --check` — passed.

#### Internal automatic review

- Iterations: 2
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: validator now rejects malformed company id explicitly, before treating a `NULL` ЦФО as valid legacy state.
- FOLLOW-UP: Stage 7.7.2 must wire document writers and manual forms; Stage 7.7.3 must switch the P&L aggregation key with migration review.

#### External Claude Code review

- Iterations: 3 attempts total. The first two read-only attempts with `--max-turns 20` ended with `Error: Reached max turns (20)` and produced no findings / no `REVIEW_GREEN`; the owner approved a retry with `--max-turns 40` and the same read-only restrictions.
- Result: REVIEW_GREEN.
- Confirmed findings fixed: none.
- Rejected findings with reason: none.
- MINOR accepted without code change: `Document.php` contains harmless CS-Fixer formatting of pre-existing `getSource()` / `getStream()` one-liners on a touched file.
- FOLLOW-UP: consider moving shared Project×ЦФО pair assertion into the Company facade if Finance/Cash validation starts to drift.

#### Review fixes applied

- Added malformed company id validation coverage to keep the Finance validator fail-closed at the module boundary.

#### Risks / reviewer focus

- This stage intentionally maps storage and validator only. It must not be reviewed as if documents already propagate ЦФО or P&L totals already split by ЦФО.
- `FinanceResponsibilityCenterPairValidator` rejects a non-null ЦФО without a project, while preserving all-null legacy rows.

#### Open questions

- none.
