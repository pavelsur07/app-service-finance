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

## Verification after first fresh review

All eight confirmed findings resolved. Core38tests98assertions, query/access/period28tests88assertions, UI26tests170assertions; independent two-connection tests verify company and book lock behavior. Details and later expanded totals in stage reports.

## Second fresh internal full-task review

Found B0/I3/MINOR2. Two IMPORTANT confirmed and fixed:
- Period straddling opening: cards/statements now return and display effective_from; entirely-before-opening shows accounting_started=false. Backend red→green4tests32assertions, functional UI regressions green.
- Historical draft references: once-per-object document_referenced audit markers for accounts/articles/ancestors survive edit/delete. Structural deletion checks the indexed marker. Root deletion/sorting regressions red→green2tests5assertions; core marker regression green.
- MINOR missing reopen grant cell fixed and tested red→green; reparenting now gets an available order and sorting normalizes ties before swapping, recording before/after orders.

One IMPORTANT classification rejected with technical evidence and recorded FOLLOW-UP: arbitrary pre-existing corruption of an existing cached balance requires an out-of-band SQL write. Source search found all authorized state writers: creation writes zero; posting/reversal updates exact delta atomically with immutable journal under company/book locks; explicit rebuild validates history and reconciles before commit; deletion only removes never-referenced accounts. Thus state=journal is preserved inductively across supported commands. The suggested per-post full-prefix SUM would reintroduce the confirmed external performance issue. Period integrity checks and explicit owner rebuild already detect/repair drift; proactive scheduled drift monitoring remains separate future work. No application trigger producing drift was demonstrated by either independent reviewer or core audit.

## External completion

- Round1: B1/I2 confirmed and fixed.
- Round2: tool failed with Reached max turns(40), not green.
- Corrected retry: exact-file/embedded-diff guidance prevented repeated broad exploration; Claude returned REVIEW_GREEN, B0/I0. Two MINOR: reopen grant cell already fixed; audit labels corrected to actual action names and Twig lint passed.
- Subsequent IMPORTANT-only corrections above verified internally; no new BLOCKER requiring another external invocation. Fresh acceptance internal review subsequently returned INTERNAL_REVIEW_GREEN.

Global module-gate failures were task regressions, not dismissed as baseline. Balance editors retain exact manage/prepare/post GET restrictions; mixed-route runtime tests now assert403 for named restricted routes, still exercise POST on every route, and assert mapping coverage. Period POST read-only path fixed before form handling. Static route policies are exact controller/permission matches with executable token checks, not namespace exemptions. Final full suite is rerunning after these corrections.

## Fresh acceptance internal completion

Third fresh ephemeral read-only session reviewed complete11113-line task diff and relevant source. Returned INTERNAL_REVIEW_GREEN, B0/I0. Two safe MINOR corrections: align journal same-date posted ordering with posting sequence, and preserve populated document form for expected amount-validation errors; targeted checks green: journal2tests16assertions; document precision/form preservation3tests67assertions, focused static/style checks passed.

Final external REVIEW_GREEN precedes these IMPORTANT/MINOR-only corrections; all confirmed findings are resolved through focused test/review cycles without another external round as required by policy. No new BLOCKER appeared after the external snapshot.

Final verification: isolated full suite4942tests28335assertions green, full PHPStan/style/strict gates green, CI code4b9df397 all checks green. No confirmed open BLOCKER/IMPORTANT.
