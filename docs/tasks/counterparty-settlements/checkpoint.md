# Current checkpoint

**Phase:** Stage 5 / Work item 5.2 — full gates and final review
**Status:** checking / reviewing
**Task base commit:** `4fdad15c974830d13ff9342d1f40a526f3b5bd33`
**Stage base commit:** `89ec6ea49`
**Branch:** `feat/counterparty-settlements`
**Worktree:** `/home/deploy/.codex/worktrees/3821/app-service-finance`
**PR:** https://github.com/pavelsur07/app-service-finance/pull/2901 (Draft, master base verified)

## Exact next action

1. Full first complete suite8400/47261 ended1E/4F, all4root causes identified and fixed: transfer preflight/closedEM (incl bulk), legacy exact DTO31 representation, GET READ+POST WRITE mixed route contract, bigint int mapping via existing MoneyAmountType. New previewREAD mismatch found in fixreview also fixed, testRED403→green. Flaky split identity test now compares unordered exactIDsets (refresh changescollectionorder, neverIDs).
2. Final external1round B0/I1/MINOR2: linkedFinanceHTMLdelete500 fixed, newtestactual /documents/{id}/delete RED500→green; partyindex added migration+schema extension. N guardqueries on companymaintenance recorded FOLLOW-UP because source-ownedbatch contract needed; no crossmoduleSQL shortcut. Result fixed without re-run perpolicy. Fresh bounded review noopenB/I.
3. Targeted allfailedgates+module+legacy source191/942 PASS. Focused34PHPfiles CS fixes1format-only; final fresh307migration36statements and existing306→307 snapshotproof PASS on fresh_final/migration_final DBs. FinalSchemaInSync running (correctpath tests/Integration/Shared/Doctrine/...), prior typo command failedfile-notfound, no application failure.
4. Commit current task checkpoint, merge origin/master (407ec649c) into FEATURE branch, retainboth services_test.yaml additions (onlyconflict). Then full finalgates on mergedtree. Protect alltaskchanges; nofeature→mastermerge. Finalize docs/commit/push/PRReady and single namedmigration+deploy approval.

No merge, production migration or deploy approval requested/received. Do not merge.

## Completed Stages

- Stage1 CLOSED `66e666657`: primary documents/book/items/journal, Money, openings/corrections/reversals, company FKs/idempotency/sequence. PASS27/103. Internal B0/I4 fixed; external1 round B0/I1/MINOR3 fixed without re-run.
- Stage2 CLOSED `81ba2ffa5`: explicit Cash/Finance sources and complete material source mutation guards, atomic 1C overwrite, real locks/leases. PASS119/608. Internal B0/I5 fixed; external1 round B0/I2/MINOR3 fixed without re-run. Inventory: source-write-paths.md.
- Stage3 CLOSED `a3b1ba860`: M:N/manual classification, historical report/card Money DTO, independent verification/rebuild. PASS87/441; focused41 files CS/PHPStan PASS. Internal B0/I1/MINOR2 fixed; external1 round REVIEW_GREEN B0/I0/MINOR4, three fixed, one guard-removal suggestion rejected with authorization rationale.
- Stage4 CLOSED `89ec6ea49`, pushed: complete native Twig UI, Documents list/detail, source forms, company/CSRF/access, bounded previews, exact Money. Final targeted144/725 PASS, focused33 files PHPStan/CS PASS, Twig21 PASS. Internal B0/I3/MINOR1 fixed; external1 round REVIEW_GREEN B0/I0/MINOR3 fixed/verified. Offline browser smoke5 pages×2 sizes PASS.

## Stage5 Work item5.1 completed

- Fixed-date AcceptanceTest covers exact A1–A10, existing concurrency owns A11. MockClock before EM/listeners, Moscow today 10.11.2026, cutoff01.11.2026. Existing ClockInterface injected into MutationPolicy after correct RED of Nov8 reversal; SystemClock production semantics unchanged.
- P01–P24 passed and mapped to owning tests in acceptance.md. Module final PASS153 tests/719 assertions, `/tmp/vf-settlement-stage5-module.log`; focused changed10 files CS/PHPStan PASS.
- Independent replay accepted consistently corrupted base document+item before fix (8 tests proved RED). Shared PostingPolicy checks legal kind/bucket/source; history cutoff/today/sequence/source snapshots checked. Verify/rebuild validate locked active Cash Money/party/direction/date and Finance usability/party. Closed historical periods remain replayable; refusal preserves journal/links. Acceptance21/107 PASS before final extra projection checks; final153/719 includes all changes.
- One-line Settlement registry entry in existing tests/Architecture/ModuleBoundaryRules.php; no new test directory/framework. Owner was told folder predates task at cf1ef467c,2026-08-30, technical author deploy; Git cannot establish approval history.
- Additive migration proof: fresh DB all307 + schema1/4 PASS. Third isolated DB migrated306, seeded1Cash40000.00 +1Finance/operation100000.00, applied307; material source snapshot unchanged, all6 new tables empty. Log `/tmp/vf-settlement-existing-migration.log`.

## Full gates / environment

- Initial full Make attempt stopped at PHPStan: only3 obsolete unmatched ignore entries for task-fixed methods, no real code errors. Removed those3 baseline entries, no regeneration/growth. Initial attempt did not reach CS/unit/full tests.
- Current full command: `make -o site-composer-install DOCKER_COMPOSE=/tmp/vf-settlement-compose site-stan site-cs-check site-cs-strict-types site-test-unit site-test`.
- Wrapper executes Make CLI recipes in existing vf-settlements-php, this worktree mounted /app, original vendor read-only; composer.lock SHA256 matches original vendor checkout. Only reinstall skipped with -o, all cache/DB/migration/test prerequisites run.
- Helper `/tmp/vf-settlement-check.py` silently picks isolated local DB vf_settlements_test; override VF_SETTLEMENT_TEST_DATABASE for fresh/migration DBs. No production config/data, no new dependencies.
- Missing test_money_holder is expected: SchemaInSyncWithMigrationsTest excludes App\Tests metadata; Money mapping test owns create/drop fixture. Do not create it permanently.
- Exact CS paths/config .php-cs-fixer.php, never format whole legacy modules. Original owner checkout/branch/unrelated artifacts untouched.

## Important contracts / limitations

- Source lock order company→sorted Cash (including Finance links)→Finance→book→sorted items; maintenance exclusive company. No live external side effects.
- Event order effectiveDate,eventSequence, never UUID. All future historical intervals/buckets validated. PAYMENT correction forbidden; source Cash is authoritative for money.
- Separate six buckets, no netting. Full Finance obligation independent P&L. RUB only; shared Money, exact strings; overflow limitations of shared Money remain.
- Report one SQL snapshot, batch labels including archived, cutoff, strict due<to, bounded pagination. EXPLAIN10k fixture42.728ms, not production SLA.
- UI native company-scoped CounterpartyPicker choice via kit theme. TODO(ds): enhanced app adapter; no legacy Tabler assets copied. No JS/assets/dependency changes.
- release.md read: Version20261106183000 adds6 tables, no existing data transform; down aborts to preserve records. Final reply template `run the migration and deploy #2901`. Code rollback leaves additive tables.
