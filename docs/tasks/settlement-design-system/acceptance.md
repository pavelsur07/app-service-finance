# UI acceptance — Settlement as Transactions DDS

**Scope:** only Twig/assets/tests/docs; product PHP/API/Money/DB/roles/CI unchanged. Base3c2cabc39; isolated worktree3821; PR2907 base master.

## Browser evidence

Real local test HTTP server, synthetic company/user data in dedicated test DB, compiled Vite assets, ru-RU, Inter/logo. No production data. Repro scripts and images (ignored, not shipped):`site/var/settlement-ui-evidence/`.

| Evidence | Result |
|---|---|
| Before:summary/card/documents/new,1440/1280/768/390 |16snapshots; existing app shell |
| DDS reference list,1440/1280/768/390 |4snapshots, legacy shell |
| After:13GET screens×4viewports |52cases, HTTP200 |
| Long name/123456789012345.07, filtered-empty, invalid period/date/counterparty×4 |20cases, expected200/422 |
| All72cases | page overflow0, JS pageerror0, broken logos0, Inter loaded |
| Reader10screens | no POST submit; write fields disabled, GET filter works |
| No-JS | native select and period inputs visible/usable |
| Picker API/keyboard | selected ID in native select; name search200 |
| API failure | local choices fallback remains selectable |
| Invalid0.001 POST | amount/reason retained, visible errors |
| Pill | period/limit/party context preserved, page removed |
| Invalid party | enhanced form exposes visible alert |
| Row dropdown at768 | menu visible inside viewport, screenshot row-menu-768.png |

Status/date/reversal/source defaults, signed−40000,60000remaining,120000liability vs100000P&L,0.30,CSRF/idempotency/company isolation are verified by existing Journey/Validation/SourceForms/Access/Acceptance tests plus11UI regressions. Existing financial assertions were not relaxed. Existing selector changes only:h1 page root, KPI .col, bounded table root.

## Visual comparison

DDS shared shell/typography/vf-ops-head, cards/tabs/filter pills/table header/row metadata/footer match reference. Forms reuse dds-op grid/cards/info aside/action bar, without data-dds-op and transaction-specific JS. Six balances stay distinct,3/2/1columns for ordinary values; a long balance spans extra width. Tables scroll internally. Both layouts already had Settlement navigation; legacy sidebar active state verified.

Shared legacy mobile navigation stays as on the DDS reference; global navigation redesign is outside this UI task. Native date formatting follows browser locale. No app kit/global tokens/new dependency/React/Vite entry.

## Bundle

Legacy CSS31.42→34.01kB (gzip6.15→6.61); JS45.55→45.91kB (gzip14.77→14.89). Scoped presentation, no unexpected>10% growth.

## Verification/review

Baseline:26Settlementtests/164assertions;23picker tests/58assertions; frontend lint/build/67tests PASS.
Final targeted:37Settlementtests/252assertions PASS before the final test type assertion; final11UItests/89assertions PASS; full suite covers final test code. Twig18Settlement templates+shared theme syntax PASS.
Handoff PHPStan3861files,CS/strict3897files,unit4547tests/25384assertions; frontend lint/build/67tests PASS. First full suite:8496tests/47735assertions,1environment failure:browser fixtures left5movements; existing Lifecycle test asserts an empty global table. After suite cleanup targeted Lifecycle PASS1test/11assertions. Browser DB separated; clean rerun PASS:8496tests/47743assertions,06:59.689. External Claude round1 REVIEW_GREEN (0B/0I);3MINOR presentation refinements fixed,11UItests89assertions/Twig19 PASS; final3browser refinements at390 on separate DB PASS.
Fresh internal reviewer:3IMPORTANT+1MINOR found/resolved; second inspection0open. Earlier Stage internal2IMPORTANT fixed. Total internalB0/I5,open0. External:round1 REVIEW_GREEN,B0/I0,3MINOR fixed (period opening/date chip/breadcrumb placement).

## Decisions/deviations

- Existing branch/PR reused per one task/one PR, despite plan suggesting separate implementation branch.
- Business journeys and source/readonly amounts reuse existing stronger tests; no duplicated backend rules. Dated mutation history uses existing MockClock10Nov in SettlementAcceptanceTest; browser uses dates1–7Nov, production clock unchanged.
- Initial browser harness errors (environment/bootstrap, invalid source fixture name/account, empty period before book) were corrected in ignored local scripts; no product contracts changed to make fixtures work.
- Full PHPStan caught nullable getReference in a new test; explicit type assertion fixes it. Failed CS invocation was corrected with explicit config/path-mode; no style failures.
- gh pr edit failed on retired Projects classic GraphQL field; existing gh api PATCH updates the same PR. No workflow/permissions changed.

## Commands/environment

- `make -o site-composer-install DOCKER_COMPOSE=/tmp/vf-settlement-compose site-stan site-cs-check site-cs-strict-types site-test-unit` — local running PHP8.4container with worktree mount and RO vendor; dependency install skipped because exact composer.lock matches installed vendor.
- `make -o site-composer-install DOCKER_COMPOSE=/tmp/vf-settlement-compose site-test` — local vf_settlements_test; clean rerun after0movements assertion. Browser uses separate vf_settlements_ui.
- Existing Node24container with copied ignored dependencies (identical yarn.lock):`npm run lint && npm run build && npm run test:unit`.
- `php bin/console lint:twig templates/settlement templates/form/counterparty_picker_theme.html.twig` —19files PASS.
- `site/bin/external-review.sh 3c2cabc399226cceb33a10d41a10b3d5ca6a906d --effort medium --context /tmp/vf-ui-review-context.md` — REVIEW_GREEN, round1; no re-run required for3MINOR fixes.

CI product checkpoint d0cd76c41:Frontend Lint PASS(run42343484219); main workflow all gates SUCCESS(run42343484341). Event pull_request, no production deployment.
