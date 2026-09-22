# moysklad-stock-sync Stage 3/4 — handoff

**Branch:** `feat/moysklad-stock-sync-ui` · **PR:**
[#2500](https://github.com/pavelsur07/app-service-finance/pull/2500) · base `master`

## Summary

- Добавлен защищённый ручной POST-запуск `SyncStockSnapshotMessage` для
  tenant-owned active+connected подключения МойСклад.
- Batch read model теперь отдаёт store/stock run, cursor, пять последних
  запусков и последний completed snapshot с числом строк без N+1.
- Legacy Twig-карточка показывает статусы складов/остатков, counters, cursor,
  историю и snapshot; read-only/inactive/unverified не получают mutation-форму.
- Running recovery-кнопка остаётся доступной и переносится в узкой карточке.

## Files

- `site/src/MoySklad/Controller/` — endpoint и подключение batch status model.
- `site/src/MoySklad/Infrastructure/Query/MoySkladSyncStatusQuery.php` — общий
  tenant-scoped status/snapshot query.
- `site/templates/moy_sklad/connections/index.html.twig` — store/stock UI.
- `site/tests/Functional/MoySklad/ConnectionsControllerTest.php` — endpoint,
  isolation, snapshot selection, ordering, UI states and permissions.
- `ARCHITECTURE.md`, plan/checkpoint/Stage reports — contracts and evidence.

## Migration and contracts

- No migration. Existing Stage 1 schema is reused.
- New internal HTTP contract: `POST /moy-sklad/connections/{id}/sync-stock`.
- Existing Messenger message/routing, runner, retry, lock and stale recovery are
  unchanged.

## Checks

- MoySklad functional: 32 tests, 232 assertions, PASS.
- `make site-stan`: PASS, 2687 files, no errors.
- `make site-cs-check`: PASS, 2702 files, 0 fixable.
- `make site-cs-strict-types`: PASS, 2702 files, 0 fixable.
- `make site-test-unit`: PASS, 3023 tests, 16503 assertions; 4 pre-existing
  deprecations.
- `make site-test`: PASS, 5230 tests, 29611 assertions; 6 pre-existing
  deprecations.
- Twig CS, frontend lint/build: PASS. Global UI Kit checks remain at their
  pre-existing red baselines (8996 class findings, 47 React mappings); this PR
  changes no React/UI Kit file and uses existing Tabler utilities.

## Reviews

- Internal fresh review: 0 BLOCKER, 1 IMPORTANT and 1 MINOR; both fixed and
  verified by focused tests. Open: none.
- External review: round 1, exact result `REVIEW_GREEN`; 0 BLOCKER,
  0 IMPORTANT.

## Risks and limitations

- Real 320/375 px browser screenshots could not be produced because no browser
  is installed. DOM tests plus the loaded Tabler 1.2 rules verify the running
  form is capped at 100% width and its button text can wrap.
- Production sync POST/dispatch is data processing and was not executed. It
  requires a separate named approval outside merge/deploy.

## Owner decision

Ready: PR #2500 "feat(moysklad): add stock sync controls and status" — merge
into master with automatic production deploy?

Reply: "merge and deploy #2500"
