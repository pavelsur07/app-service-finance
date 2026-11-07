## Current checkpoint

**Phase:** handoff. **Status:** local work done; PR Ready; awaiting CI/owner decision.
**Stage 1 base:** `6a6f7d945`. **Stage 2 base:** `61383f407`.
**Branch:** `docs/ozon-realization-duplicate-repair-plan`.
**PR:** https://github.com/pavelsur07/app-service-finance/pull/2913 (base master).
**Implementation commits:** `61383f407`, `ec19cd076`, `b2cd4f228`.
PR Ready/master verified; task worktree clean after each push.

### Completed

- Phase 0 baseline and two Stages complete; reports in stages/stage-{1,2}.md.
- Shared company/marketplace/period transaction lock + raw row lock/refresh prevent
  duplicate writes from Action/manual/handler and protect final/finance-locked months.
- Fixed-target repair dry-run is SELECT-only; execute requires expected raw hash,
  exact doubled multiplicities and final document. Atomic reopen/process/final-close
  validates dimensions, links, complete canonical register, then invalidates cache.
- Legitimate identical raw rows preserved; partial mismatches refuse without mutation.
- Shared strict header parser supports date-only and ISO date-time calendar dates.
- Reopen business refusal keeps EntityManager usable. No new dependencies/migrations.
- production-runbook.md contains concrete backup, temporary permission, execute,
  read-only acceptance and rollback process. Production unchanged.

### Checks and baseline

- Initial baseline ProcessOzonRealizationRegressionTest: PASS2/8.
- Old-code concurrent duplicate and rollback/guard regressions proven RED.
- Marketplace+Finance module PASS749/3614; unit gate PASS4547/25384 before final fixes.
- Isolated canonical make site-test PASS8521/47893; final full PHPUnit after runtime
  fixes PASS8532/47908. Last fixture-only correction verified process tests PASS3/19.
- Full CS and strict-types PASS0/3909; last fixture focused CS PASS0/1.
- Final full project PHPStan2G PASS No errors /3873 files. Baseline only shrunk:
  7 obsolete patterns /8 occurrences removed; 0 new suppressions.
- Focused PHPStan11 files PASS; last header unit PASS8/8; command help/diff check PASS.
- Earlier failures and corrected environment/resource conditions recorded in handoff.md.
  Dedicated local DB app_test_ozon_realization_repair, task container
  vf-ozon-realization-isolated-tests. No production credentials in local checks.

### Review status

- Internal overall B1/I3, fixed with RED→GREEN; fresh independent final check
  remaining B0/I0/M0. Full diff reviewed; final fixture exit fix self-reviewed.
- External Claude1 round B0/I2/M1, all fixed; 11 tests/15 assertions PASS.
  Result: fixed without re-run, AGENTS.md §7.2. No REVIEW_GREEN marker claimed.

### Exact next action

- Final fixture correction and handoff committed/pushed; PR body updated and Ready.
- Master base and head verified. Final CI watch launched; await result and ask
  the single merge+deploy decision. No application code changes remain.
- Named backup/temporary wrapper/execute approvals are separately required;
  absent, no production mutation. Approval of plan/implementation is insufficient.

### Production evidence / limits

- Company b57d7682-505f-4b74-86f8-953d32d59874; raw dda3485d-1aa8-48b5-9e12-bc8f06ddb239.
- October2026: raw14258 vs normalized28516; all12677 signatures exactly2x.
- Expected Ozon sales9333035.55 /returns66744.22 /net9266291.33;
  existing net18532582.66. Including unchanged WB expected9280441.14.
- Raw md5 304dd45f03283e2973b9b78e50b40635. Category mapping is correct.
- Header dates verified date-only; source PL project matches connection, CFO null.
- Concurrency mechanism reproduced; historical production overlap not proven by logs.

### Files to inspect first on resume

- handoff.md; production-runbook.md; git status; PR CI.
- No merge/deploy, backup, wrapper edits or execute have been approved/performed.
