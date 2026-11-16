# ds-v3-apps — Stage5 handoff

**Branch:** feat/ds-v3-stage-5
**PR:** https://github.com/pavelsur07/app-service-finance/pull/3039 (master)
**Stage base:**03a34a2db176d0ecbb67ad904f28b8a7760c57c2
**Integration base:**5a7725216; runtime04703c014, integration3a3d74aa3 preserves apps.
**Runtime/CI:** latest PR head/checks; Ready only after exact-head CI success.

Stages1–4 delivered PR3027/3030/3034/3037. Stage5 completes specified §3.1
Data/Tags/Table/Tabs/KPI/overlays/pickers/formatters. Account uses DS Pagination
in existing server CompanyPicker; Sidebar reuses Avatar. No financial formulas,
auth/store/API/new business screens or data changes. Native dialog/popover need
no new dependency; native top-layer notifications explicitly scoped.

Files: apps/packages/ds components/format/styles/exports/docs, account picker,
shared Sidebar, test-only advanced fixture/tests, apps README/task docs.
site/, deps/locks/generated and production config unchanged. No migrations.

Checks: frontend types/lint/tests/build140/136PASS, package105;48browserPASS
(27advanced/12base/9layout), text4.5/control3/approved icons; required/FormData,
IME, menu, drafts/leaf-tree, native inert/trap/scroll/restore and same-commit
nesting. Preview40PASS10skips; actual nginx44PASS6skips+fixture47, final
required correction fixture48.17before/after +8fixture screenshots; loading
baseline reused delivered Stage4 identical expanded shell with provenance.
PHPStan/CS/strict/unit4928PASS. Final actual image equivalence/CI linked in PR.

Review metrics: internal BLOCKER/IMPORTANT0/4fixed (author3 +fresh reviewer1),
MINOR1fixed; fresh independent +focused INTERNAL_REVIEW_GREEN/open0. External
Codex1round BLOCKER/IMPORTANT0/1fixed; **fixed without re-run**, no raw green
marker claimed. Native required validates committed tags; draft not serialized.

Risks/follow-ups: CSS+11451B (~31.8%) shared Stage5 utility surface; account
JS+2439B (~0.82%), admin+605B (~0.20%). Apps JS tree-shakes unused components.
Native dialog/ToastStack scope gap documented TODO(ds); P1 calendar/auth/error/
loading/combobox/bulk table/dark deferred pending sheets. Details stage-5.md
and checklist-stage-5.md; no open B/I.

Ready: PR #3039 "feat(ds): Stage 5 — данные, оверлеи и форматтеры" — merge into master with automatic production deploy?
Reply: "merge and deploy #3039"
