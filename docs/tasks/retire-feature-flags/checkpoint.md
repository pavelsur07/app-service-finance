## Current checkpoint

**Phase:** Stage 1 / Work item 1.1
**Status:** implementing
**Stage base commit:** 0e14475c9

### Completed
- Phase 0: изолированный worktree feat/retire-feature-flags от актуального master.
- Прочитаны правила, план, ServiceGateway/SourceFacts/Telegram архитектура.
- Pre-flight: Stage 1 grant API используется Stage 2; сигнатуры Facade сохраняются.
- Pre-flight: Stage 2 --all используется Stage 3 cron; UUID mode сохраняется.
- Ruling: текущий запрос реализации покрывает предусмотренные изменения CI/CD и Compose; production и Variables только после отдельного handoff-разрешения.

### Checks and baseline
- make test-deploy-workflow — PASS (shell + 10 Python).
- ServiceGateway Unit — PASS (184 tests, 884 assertions).
- SourceFacts/Telegram baseline — running.

### Review status
- internal: pending
- external: pending

### Exact next action
- Регрессионные тесты AiServiceAccess без rollout и SourceFacts без scope: RED → GREEN.

### Stage 1 complete
- 263 tests / 1186 assertions PASS. RED→GREEN подтверждён.
- SourceFacts/Telegram baseline: 74 tests / 564 assertions PASS.
- Internal: BLOCKER 0, IMPORTANT 0. External: handoff pending.
- Next: Stage 2 — tests for admin-granted nonpilot and recovery --all.

### Stage 2 progress
- 2.1: pilot class/guards removed; AI checks retained for new parse/edit/retry.
- 2.2: recovery UUID/--all, sorted union of grants/pending sessions, isolated channels.
- DI bindings removed in Stage 2 because the class is removed here (Stage 3 remainder unchanged).
- Telegram functional: 58 tests PASS; all-company integration: 3 tests / 19 assertions PASS.
- Focused PHPStan: PASS. Focused CS: PASS after formatting test files.
- Next: module result + complete Stage diff review/external review (>500 lines).

### Stage 2 complete
- 182 tests / 1278 assertions PASS.
- External 1 round: BLOCKER 0, IMPORTANT 1 fixed without re-run (RED→GREEN), MINOR 2 fixed.
- Ruling: recovery includes event-only Cash deliveries without AI grant/session.
  Reason: Confirm must preserve financial invalidation after a transport failure.
  Cost if wrong: extra read-only enumeration/event retry; no new payment or LLM.
- Next: Stage 3 — apply deployment changes to prepared RED runtime tests.
