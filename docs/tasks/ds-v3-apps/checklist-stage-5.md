# DS v3.4 — приёмка Stage5 / итог §A–F

Канон: Git3882c5dd9:docs/design-system/CHECKLIST-ds-v3.md.
Доказательства: stages/stage-5.md, reviews/codex-stage-5.md; команды/логи и
скриншоты /tmp/vf-ds-v3-stage5. CI/latest head — в PR3039.

## A. Scope
- [x] ⛔ site/ не изменён: own diff к master пуст для site/.
- [x] ⛔ Нет dark CSS: scope-audit.json +source audit.
- [x] ⛔ Нет старого ui/styles/site/assets CSS imports: source audit.
- [x] Новых deps/locks нет; Node/npm только контейнеры.

## B. Пакет и токены
- [x] apps/packages/ds exports .,tokens.css,styles.css — package.json.
- [x] Style Dictionary генерирует --ds-; light only — check:generated prebuild.
- [x] Все361токен/source values — tokens/styles unit contracts в full suite.
- [x] Runtime code без raw colors — source audit, generated tokens excluded.

## C. Tailwind и стили
- [x] Tailwind4/preflight/theme обоих apps — build/typecheck; unchanged setup.
- [x] Default palette/type/radius/shadow disabled — styles tests.
- [x] Нет arbitrary colors — source audit.
- [x] Manrope self-hosted/body13/tabular/root16 — existing browser contracts.
- [x] Login/home/about/404 и states —17before/after1440. Loading baseline
  reused delivered Stage4 unchanged expanded shell; provenance file, new after.

## D. Компоненты
- [x] Все §3.1 exported; TS interfaces, native refs/attrs,105DS tests.
- [x] Button navy; no new solid red/background-accent controls. Confirm
  uses existing outline danger — owner checklist overrides stale source solid.
- [x] Icon-only labels/native keyboard — unit/browser; Tabler16/18/20stroke2.
- [x] Fields native states/error ARIA, text4.5/control3/focus — browser audits;
  Tags required/FormData checked on committed values, draft excluded.
- [x] Modal/Drawer top layer/inert/trap/Escape/restore/scroll lock — browser48,
  actual AppShell main/nav, nested unmount/same-commit opening, scoped Toast.
- [x] Table sort aria-sort on th/native sections/totals/Pagination — unit/browser;
  no financial computation. Tabs roving/panels, picker drafts/tree leaf-only.
- [x] ⛔ formatMoney NBSP/U2212/comma/RUB suffix,0/negative/kopecks/large decimals
  —24formatter tests, browser exact text. Expense neutral; only KPI delta color.
- [x] Apps old Button/EmptyState ui consumers0 — source audit.

## E. Каркас
- [x] Header60/logo column/breadcrumbs — inherited Stage4/layout browser9.
  Period/search/notifications supported slots, no fake unavailable actions.
- [x] Sidebar264/72/nested disclosure/current state — layout browser.
- [x] Persistence/rail group expand/focus — layout browser.
- [x] Avatar/name(real email fallback)/realm detail/logout — app E2E; no fake
  role/profile/INN. Shared Avatar now replaces duplicated initials fallback.
- [x] ⛔ Scrollbar stable/no content jump — ordinary-scrollbar layout tests;
  overlay lock verifies actual main/nav owners without width shift.
- [x] Skip link/unified focus — layout/base/app browser.
- [x] Admin own menu/shared shell — both app E2E; no session/API changes.

## F. Проверки и отчёт
- [x] make front-typecheck front-lint front-test front-build PASS140/136;
  preview40PASS10skip+fixture45 before scoped fixes, finalfixture48. Actual
  nginx44PASS6skip+fixture47 before required fix; finaldist/image identical.
- [x]17before/after +8component/full/rail screenshots, source visual inspection.
- [x] Text4.5/control3/focus, all icons allowed — computed browser audit.
- [x] Stage Report/source deviations/TODO(ds)/§4 owner decisions documented.
  Money/Icons decisions closed. P1 sheets missing remain deferred explicitly.
- [x] Independent fresh internal B/I0/4fixed,M1fixed; external Codex1round
  B/I0/1fixed, fixed without re-run; open0, no false green marker claim.

Follow-ups: generic P1 calendar/auth/loading/error/combobox/bulk table/dark
await source sheets. Native dialog/global notification routing source gap
uses explicit notifications slot; no production toast consumers/global manager.
