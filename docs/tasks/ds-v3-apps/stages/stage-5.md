# Stage5: данные, оверлеи и форматтеры — DONE

**Risk:** HIGH-LOCAL
**Stage base:**03a34a2db176d0ecbb67ad904f28b8a7760c57c2
**Branch/PR:** feat/ds-v3-stage-5 — https://github.com/pavelsur07/app-service-finance/pull/3039
**Work items:**5.1contracts;5.2data/formatters;5.3overlays;5.4pickers;5.5gates/review.

## Результат

- Table/caption/native sections, SortHeader/aria-sort, TotalsRow; bounded
  Pagination; controlled Tabs/roving/panels/counts; KpiCard neutral value/delta.
- Chip, AbcPill, MarketplaceMark, Avatar(error fallback), TagsInput с
  suggestions/create/duplicates/IME/Backspace/Escape и native props/ref.
- formatMoney/Number/Percent/Date: exact decimal strings через Intl без
  Number coercion, NBSP/U2212/comma/₽, invalid/nonfinite/calendar rejection.
  Money defaults2decimals, whole digits explicit; percent input points,
  dates UTC/explicit timezone, date-only сохраняет календарный день.
- Native Modal S/M/L, Drawer680, ConfirmDialog, native-popover DropdownMenu;
  focus/inert/scroll lock/restore/Escape/backdrop, nested cleanup/unmount.
  Open queue обеспечивает parent-before-child при одном React commit.
- EntityPicker/TreePicker: per-open draft, cancel/apply/clear/search,
  ancestor context/highlight, enabled leaf-only/tree keyboard/empty states.
- account CompanyPicker переиспользует Pagination, сохраняя native Select,
  server paging и store; общий Sidebar использует Avatar. Fake business
  screens/actions, финансовые формулы и API не добавлялись.
- Test-only advanced fixture не попадает в production routes/dist.

## Scope

apps/packages/ds components/format/styles/exports/README; account CompanyPicker;
shared Sidebar; component/e2e fixtures; apps README/task docs.
site/, dependencies/locks/generated, production config unchanged.

## Проверки

- Baseline front-test92/88PASS;15before1440 screenshots.
- SSR/formatter contracts RED missing exports/module→GREEN; frontend
  types/lint/tests/build PASS140/136. Formatter24 +data19 +tree5; package105.
- Browser48PASS:27advanced+12base+9layout, including source text≥4.5:1,
  controls≥3:1, Tabler16/18/20stroke2, scoped notifications, actual main/nav
  wheel/PageDown lock, nested simultaneous open/unmount/restore native required/FormData validation.
- Preview40PASS10skips +fixture45 before final focused queue/tag fixes.
- PHPStan/CS/strict PASS; unit4928tests/27251assertions.
- Corrected full frontend gates pass; actual nginx images/smoke44PASS6skips
  +fixture47 before final scoped required fix; final fixture48 after fix.
  Apps do not consume TagsInput/overlays; final5distfiles byte-identical to
  both tested images; only additional50x.html is unchanged nginx base asset. Latest-head real backend/HTTPS/release CI tracked in PR.
- Before/after screenshots and source contrast audit artifacts:
  /tmp/vf-ds-v3-stage5 (not Git):17before/after +8fixture. Loading baseline
  reuses delivered Stage4 after frames for unchanged expanded loading shell;
 15other before freshly captured,17after captured now; provenance recorded.

## Internal review

Fresh independent reviewer B/I/M0/1/1; both reproduced RED, fixed and focused
INTERNAL_REVIEW_GREEN;47browserPASS. Author IMPORTANT3fixed: picker reopen
reset race, invalid ISO calendar rollover, Tag Escape swallowing parent close.
Internal totals BLOCKER0/IMPORTANT4fixed, MINOR1fixed, open0.

## External review

Claude absent; separate Codex read-only ephemeral medium/stdin-only static,
full owned diff +original task/checklist/source sheets/current support.
Tools/network/nested reviewers/writes forbidden; no environment/secrets.
Round1 no BLOCKER/one IMPORTANT(required TagsInput) confirmed and fixed
RED→GREEN48 +SSR +focused independent GREEN. External **fixed without re-run**
per §7.2; no raw REVIEW_GREEN claimed. Details ../reviews/codex-stage-5.md.

## Отклонения и TODO(ds)

- Owner decisions override source thin spaces/colored expenses/small SVGs.
- Whole-money samples use explicit fractionDigits0; formatter preserves
  kopecks by default. No silently assumed percent ratios or local timezone.
- Source red solid confirm conflicts with checklist: existing outline danger.
- Marketplace canonical28 +inline16, no stale lg32; Megamarket initials use
  semantic strong text to meet4.5:1. KPI no stale colored accent strip.
- Pagination uses existing strong border for control contrast.
- Native dialog top layer outranks ordinary z-toast. Source gap recorded
  TODO(ds); active Modal/Drawer notifications slot reuses ToastStack outside
  scroll body. No automatic global routing or production toast consumer.
- Existing P1 auth/loading/error/calendar/combobox/bulk-table/dark TODOs remain
  deferred until source sheets; no invented design. Scoped native popover
  menu/Tags suggestions follow existing source, not a new generic Popover.
- New dependencies/migrations/production mutations:none.

## Environment failures (resolved)

Concurrent DS npm ci/typecheck temporarily removed Tabler; sequential types
PASS. Node-only runner lacks browsers: corrected to existing Playwright image
and pagination contract RED proved missing kit nav, then implementation.
Git merge with intent-to-add failed backup stash (also with autoStash false);
resolved by committing task-owned code first, then merging master5a7725216
(PR3038 services-only). Integration3a3d74aa3 preserves apps byte-for-byte
from reviewed runtime04703c014; own diff origin/master has no site/config/
services changes. Incoming code untouched; PHP/apps contracts unchanged.
No owner files changed. Image comparison initially included nginx base50x.html;
corrected comparison verifies every dist asset plus only that expected extra.


## Production delivery — завершено

PR3039 merged da7cef6550cfac694e00d92cf7ef7b6efaf0eff9;
[pipeline43863099728](https://github.com/pavelsur07/app-service-finance/actions/runs/43863099728) SUCCESS, deploy SUCCESS.
Readonly wrapper: оба apps healthy exact SHA. HTTPS23/23, CSS Stage5
в обоих bundles, public browser2/2PASS,0page errors/external requests.
Production login/data mutations вручную не выполнялись.

Задача закрыта 2026-11-16. Исторические /tmp артефакты после приёмки
архивированы и убраны; архив/SHA256/cleanup — checkpoint.md.
