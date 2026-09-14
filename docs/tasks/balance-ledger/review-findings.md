# Review findings

## Stage external round 1 (Claude)

Confirmed BLOCKER1/IMPORTANT2. Target-account shadow, overly broad HTTP exception handling, and unbounded posting history reads are fixed with regression evidence in Stage1 report. Four safe MINOR items resolved; retained Facade tree API has Company onboarding caller. Round2 reviews full integrated backend/UI.

## Fresh internal full-task review (Codex CLI)

Fresh ephemeral read-only session received only TASK, full diff and stage checklists. Report: BLOCKER2/IMPORTANT6. All accepted; follow-up fixes in progress until verification below is filled.

| Severity | Finding | Resolution / verification |
|---|---|---|
| BLOCKER | Post accepted unseen draft revision; zero-opening confirmation outside lock | Expected revision and explicit confirmation now part of posting command and checked under lock; awaiting final tests |
| BLOCKER | Cached financial rights and different lock boundary permit in-flight revocation race | Fresh Company financial rights; company FOR SHARE before book FOR UPDATE coordinates with existing Company role/member mutation locks; awaiting final tests |
| IMPORTANT | Reopen shared close permission | Separate can_reopen_periods field/grant/action/UI; awaiting final tests |
| IMPORTANT | Deleting draft releases request key | Durable deletion audit/tombstone rejection; awaiting final tests |
| IMPORTANT | Reversal replay bypasses payload hash | Exact replay validated before existing result; awaiting final tests |
| IMPORTANT | No current-state rebuild implementation | Owner-only locked journal rebuild with reason, audit and reconciliation; awaiting final tests |
| IMPORTANT | Sparse form collection keys collide after validation | Next index above max key, not row count; awaiting final tests |
| IMPORTANT | Movement cards load all rows | SQL pagination with full-period totals and prefix-aware running balance; awaiting final tests |

Counts are findings, not open issues; handoff will record final open count separately. No review is declared green until fixes and targeted verification are recorded. Production unchanged.
