### Stage 1: Read-only WB loaded-data financial control report — DONE

**Risk:** HIGH-LOCAL
**Owner gate:** yes
**Release candidate:** yes
**Independently deployable:** yes
**Next action:** STOP, owner action required

#### Stage scope

- Stage base commit: `b1a49db20b1f3d907b914703f3931cd39875787d`
- Work items completed: `1.1`, `1.2`, `1.3`

#### What was done

- Added a company-scoped, iterative query over WB daily statuses and their linked complete raw documents.
- Added exact minor-unit aggregation for sales, returns, commission, acquiring, WB costs, adjustments, and calculated payout without reading transaction projections.
- Added date/reportId filters, coverage and quality controls, operation/report grouping, raw JSON links, and CSV export.
- Added a server-rendered Twig page and marketplace navigation entry without a new frontend bundle or dependency.
- Added exact minor-unit Twig formatting without float arithmetic.
- Added regression coverage for financial signs, payout adjustments, malformed input, unsupported document types, tenant isolation, CSV injection, and empty/error states.

#### Files changed

- `docs/tasks/wb-finance-raw-report/` — plan, operating notes, checkpoint, Stage Report.
- `site/src/Marketplace/Application/Service/WbRawFinancialReportBuilder.php` — raw aggregation and quality checks.
- `site/src/Marketplace/Controller/WbRawFinancialReportController.php` — HTML/CSV endpoints and filter validation.
- `site/src/Marketplace/Infrastructure/Query/WbRawFinancialReportQuery.php` — iterative tenant-scoped DBAL query.
- `site/src/Twig/CurrencyFormatExtension.php` — exact minor-unit formatting.
- `site/templates/marketplace/layout.html.twig` — report navigation.
- `site/templates/marketplace/wb_finance_report.html.twig` — report UI.
- `site/tests/Functional/Marketplace/Controller/WbRawFinancialReportControllerTest.php` — functional coverage.
- `site/tests/Unit/Marketplace/Application/Service/WbRawFinancialReportBuilderTest.php` — financial/quality coverage.
- `site/tests/Unit/Twig/CurrencyFormatExtensionTest.php` — formatter coverage.

#### Definition of Done

- [x] Authenticated, tenant-scoped report with bounded date and optional reportId filters.
- [x] Only status-linked WB raw documents are read; staging and transaction projections are excluded.
- [x] Money is parsed/aggregated in minor units and formulas/sources are visible.
- [x] Coverage, quality warnings, report grouping, operation grouping, and raw JSON links are present.
- [x] CSV uses the same filters/totals and protects external string cells from spreadsheet formulas.
- [x] Unit and functional acceptance coverage is present.
- [x] No schema, queue, sync, external API, transaction, or production behavior changed.

#### Baseline

- `docker compose run --rm -T site-php-cli php bin/phpunit tests/Functional/Marketplace/Controller/WbFinanceSyncStatusControllerTest.php` — green: 4 tests, 16 assertions.

#### Checks

- targeted report/Twig tests — green: 14 tests, 65 assertions.
- Marketplace module plus relevant functional/Twig tests — green: 479 tests, 4,006 assertions.
- `php bin/console lint:twig templates` — all 218 templates green.
- `php bin/console lint:container --env=test --no-debug` — green.
- active PHP CS config over every changed PHP file — green.
- full repository PHP CS — pre-existing unrelated failure on 583 files; none of the changed PHP files fail the active config.
- full repository Twig-CS — pre-existing unrelated failure on 509 violations; Twig syntax is green.

#### Internal automatic review

- Iterations: 4
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: cost sign normalization, malformed product exclusion, unknown doc-type visibility, CSV injection, raw streaming/retention, exact money display, quality-label drift, timezone consistency, and local overflow handling.
- FOLLOW-UP: duplicate `rrdId` exclusion requires an explicit owner financial-semantics decision; aggregate overflow belongs in the shared `Money` VO; very large tenants may require driver-level streaming or SQL aggregation.

#### External Claude Code review

- Completed review iterations: 4, with two prescribed max-turn retries.
- Result: `REVIEW_GREEN`
- Confirmed findings fixed: all BLOCKER/IMPORTANT and safe in-scope MINOR findings.
- Rejected findings with reason: none.

#### Review fixes applied

- Aligned non-product costs with the existing WB `abs()` convention.
- Prevented unsupported/malformed product rows from silently corrupting headline totals.
- Escaped external report/operation cells in CSV and disabled the backslash escape character.
- Iterated raw documents and retained only a lightweight coverage projection.
- Added regression tests for every changed financial branch.

#### Risks / reviewer focus

- Verify the report remains a raw-data control view and never gains a transaction-table dependency.
- Keep commission/acquiring informational in the article table; do not subtract them again from `forPay`.
- Preserve tenant predicates on both status rows and joined raw documents.

#### Checkpoint

- `docs/tasks/wb-finance-raw-report/checkpoint.md` updated.
- Exact next action: publish the checked Stage to the task Draft PR and request the owner Release Gate decision.

#### Open questions

- Should duplicate `rrdId` rows be excluded from raw totals in a future Stage, or remain included with a quality warning?

#### Expected owner response

Recommended response:
`Проверил Draft PR. Можно переводить PR в Ready for review.`

Alternative responses:
- `Нужны изменения в форме отчёта: <описание>.`
- `Оставить PR в Draft; решение по duplicate rrdId приму отдельно.`
