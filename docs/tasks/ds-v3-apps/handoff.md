# ds-v3-apps — Stage4 handoff

**Branch:** feat/ds-v3-stage-4
**PR:** https://github.com/pavelsur07/app-service-finance/pull/3037 (base master)
**Base:** d99ede00d; plan commit0177e328c, runtime stage commit follows.
**CI:** live PR latest-head checks; Draft до завершения gates.

Stages1/2/3 delivered PR3027/3030/3034. Stage4 — общий header/sidebar/footer
и page headers, nested navigation/collapse264/72/persistence, skip link и
независимые scrollbar-stable main/nav. Public about/404 без session bootstrap;
real identity/email и прежний logout, включая pending background refresh.

Files: apps/packages/ds layout components/icons/CSS, apps/* app composition/
Home/About/tests, README/task docs. site/, backend/API/session store/CSRF,
production Docker/CI, locks/dependencies/generated токены unchanged.
Миграций и ручных production mutations нет.

Checks: front types/lint/test/build PASS92/88; package57; browser21; preview
36PASS10skips +targeted logout4PASS; final nginx builds/smoke44PASS6skips
+fixture21. PHPStan/CS/strict/unit4889PASS на stage base;17before/after1440
+full/rail snapshots. Real HTTP/HTTPS и latest code проверяются в PR CI.

Internal totals B/I0/2(author regressions fixed), independent reviewer0/0/0
GREEN +focused final GREEN. External Codex1round0/0 exact REVIEW_GREEN;
static stdin-only fallback, Claude unavailable. Post-green logout correction
verified RED→GREEN4 and fresh internal review; external re-run not required.

Risks/follow-ups: only actual actions/routes; no role/profile fake data;
caption accessible text-tertiary; TODO(ds) pending auth/error/loading sheets
и source error-border retained. CSS+12.52%/4KB justified by shell utilities;
JS+~4.7%. Stage5 data/overlays/formatters не начат.

Reports: stages/stage-4.md, reviews/codex-stage-4.md, checkpoint.md.

Ready: PR #3037 "feat(ds): Stage 4 — общий каркас account и admin" — merge into master with automatic production deploy?
Reply: "merge and deploy #3037"
