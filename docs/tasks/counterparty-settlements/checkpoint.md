# Current checkpoint

**Phase:** Stage4 CLOSED / Stage5 next
**Status:** commit/push Stage4 then Stage5
**Task base commit:** 4fdad15c974830d13ff9342d1f40a526f3b5bd33
**Stage base commit:** a3b1ba860
**Branch:** feat/counterparty-settlements
**Worktree:** /home/deploy/.codex/worktrees/3821/app-service-finance
**Draft PR:** https://github.com/pavelsur07/app-service-finance/pull/2901 (base master verified)

## Exact next action
1. Stage4 external REVIEW_GREEN (one round); all3MINOR fixed, actual3red→green17assertions.
2. Final targeted PASS144/725; focused PHPStan/CS and Twig21PASS. Browser refresh10screens PASS, results copied; commit/push/update PR.
3. Continue Stage5 automatically: frozen-date acceptance, P01–P24, existing-data migration check, full gates once, fresh final reviews, handoff/Ready.

No merge, production migration or deploy approval requested/received. Do not merge.

## Completed Stages
- Stage1 CLOSED66e666657: primary documents/book/items/journal, Money, opening/corrections/reversals, scoped FKs/idempotency and event sequence. PASS27/103. Internal B0/I4fixed; external1round B0/I1/MINOR3fixed, fixed without re-run. See stage-1.md.
- Stage2 CLOSED81ba2ffa5: explicit Cash payment + Finance draft source, fingerprints, all material source mutation guards including1C atomic overwrite and raw commands. Targeted119/608 PASS; focusedCS/PHPStan PASS. Internal B0/I5fixed; external1round B0/I2/MINOR3fixed, fixed without re-run. Source write inventory complete, real PG lock/lease races tested. See stage-2.md.
- Stage3 CLOSEDa3b1ba860: M:N allocations/classification/reversal, exact historical report/card DTO/Facade, independent primary projection/verify/rebuild. PASS87/441; focused41PHPfiles PHPStan/CS PASS. Internal B0/I1/MINOR2fixed, bounded rereview B0/I0. External1round REVIEW_GREEN B0/I0/MINOR4:3fixed, guard-removal suggestion technically rejected (authorization before allocation lookup remains distinct from inner fresh write lock). See stage-3.md.

## Stage4 implemented, uncommitted
- New UI: setup/cutoff, summary/card, separate Documents list/detail/post, manual opening/obligation, Cash include, explicit full-amount Finance draft, allocation/correction/classification and dated cancellation forms. Existing sidebar/source/counterparty links. ModuleAccessMap FINANCE, company scope/CSRF/requestId/version.
- Strict RUB form rejects excess precision BEFORE Money::fromString; exact DS rendering. Form constraints reject blank choices/date/IDs; false bool allowed.
- Card movement report is paginated; optional unbounded current-position table removed. Form balance preview50 labelled rows, full scoped choices retained; linked full paginated Documents and per-document free balance/classification.
- Allocation HTTP retry retains only matching submitted IDs among scoped positions so an exhausted payment can reach Action replay.
- Document list cancellations loaded in one batch; detail links both allocation sides.
- Current targeted Settlement+affected legacy view set PASS142/719 (/tmp/vf-settlement-stage4-final.log). Focused changed33PHPfiles PHPStan/CS PASS; final review-fix forms/controllers/tests PHPStan PASS. Twig21files PASS.
- Fresh internal Stage4 reviewer /root/review_settlement_stage4: B0/I3/MINOR1, all fixed and bounded rereview no open findings. I1 null fields500 HTTP red4cases→green; I2 old setup tab configured switched company, company-bound CSRF red→green1/2; I3 many invoices rendered242rows, bounded read/UI red→green1/8. MINOR identical allocation POST red422→green Journey1/26, exactly1allocation.

## UI evidence / rulings
- Browser: cached Playwright1.56.1 image/package, no dependency added.5 synthetic rendered HTML pages x1440/390=10screens, no page horizontal overflow/unlabelled controls/JS errors; keyboard focus visible. HTML fixture transaction rolled back. Artifacts site/var/settlement-smoke/, results.json. Offline DS CSS smoke, business requests tested via BrowserKit.
- App layer lacks Tabler-specific legacy picker assets. Existing CounterpartyPickerType (browse=true) retains company-scoped validation; local theme uses kit-styled native choice widget. TODO(ds) enhanced app adapter is follow-up; no copied legacy CSS/JS/new JS arithmetic.
- Legacy external IDs follow existing Ramsey validation instead of stricter RFC-variant route regex; malformed/foreign IDs404. Internal event IDs use UUID route requirements.
- Reversal/classification routes GET+POST to collect explicit date/reason; mutation Actions unchanged.
- Stage4 risk HIGH-LOCAL (financial HTTP mutations/access map), correcting lower plan MEDIUM; >500lines requires one external round. No owner gate between Stages.
- Closed-date drafts are permitted; posting is restricted. One test initially asserted the wrong draft rule, corrected to posting without changing financial semantics.
- Owner asked about tests/Architecture: folder pre-exists task (cf1ef467c,2026-08-30, technical author deploy), no task edits there so far. Explained approval history cannot be inferred from Git.

## Environment / checks
- Running local vf-settlements-php mounts THIS worktree site at/app and original vendor read-only. Original checkout has unrelated owner artifacts, untouched. Composer lock matches reused vendor checkout; no new deps.
- /tmp/vf-settlement-check.py silently reads tracked .env.test local URL, redirects DB to vf_settlements_test; optional VF_SETTLEMENT_TEST_DATABASE for isolated DB. No production env copied or accessed.
- vf_settlements_test all306base+Version20261106183000 applied; second fresh vf_settlements_fresh_test all307 applied and schema test1/4 PASS. Migration additive6tables, down abort preserves financial records.
- Full gates have NOT run yet (only at handoffStage5). Make targets use DOCKER_COMPOSE override; prepare task-local wrapper to execute same commands in running container without vendor writes. Composer lock verified identical.
- Schema validate includes missing test_money_holder fixture; actual SchemaInSyncWithMigrationsTest excludes App\Tests metadata, so do NOT create that table permanently. Its own mapping test creates/drops it.
- CS config .php-cs-fixer.php (not dist); format exact task files, never whole Cash/Finance modules.
- Baseline original Cash allocation7/15, Finance create-from-Cash11/49 PASS. No production commands.

## Important contracts
- Source lock order: company → sorted Cash (including Finance linkedCash) → Finance → book → sorted items. Maintenance exclusive company lock, active source fingerprint checks, then book; no primary modifications.
- Persistent eventSequence under book lock; no UUID event ordering. Bucket history validated at every event. Manual PAYMENT correction forbidden; actual source Cash remains authoritative.
- Policy allocation capacity takes actual Money availability, not original amounts. ALLOCATION event kind explicit. If payment spans advance+unallocated, require explicit classification; no automatic priority.
- Reports require from>=cutoff; opening excluded from turnover, six buckets separate, dueDate strict <to, absent due date separate. One SQL snapshot page/totals/card; labels batch including archived. SUM checked beforeintcast. Real EXPLAIN10000movement fixture42.728ms, report-explain.json/performance.md.
- Existing Money overflow limitations must not be bypassed via local money helpers. All new monetary arithmetic uses shared Money.

## Stage5 preparation read (not implemented yet)
- release.md read: final approval must name Version20261106183000 and separate production migration+deploy; reply template run the migration and deploy #2901.
- Acceptance fixed Nov6/7/8 dates require injecting existing ClockInterface into SettlementMutationPolicy (System clock default behavior unchanged), then MockClock in acceptance test. Existing Billing tests show container replacement pattern. Record ruling/test proof.
- Existing migration immediately before307 is Version20261106120000. For P18 use isolated local third DB, migrate through306, seed existing Cash/Finance, apply307, compare exact snapshots and zero auto settlement docs.

## Stage4 closure
- External1round REVIEW_GREEN B0/I0/MINOR3 all fixed and verified. No re-run required.
- Final targeted144/725 PASS; final focused PHPStan/CS PASS; Twig21PASS. Stage4 Report DONE.
- New classification guard checks four payment buckets, preserving opening advances as allowed by existing Action; no kind-based financial restriction invented.
