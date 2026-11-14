# Stage1 — DONE
Risk HIGH-LOCAL; stage_base_commit9bae6bb02 (runtime same as taskbase ae0b812c).

Implemented strict Telegram-owned company policy and gate before input/source-session recovery/callback/poll/publication/company recovery. Generic Cash/Gateway and AI grants unchanged. --skip-cash-recovery makes later independent recovery script possible; default command behavior retained inside eligible scope. Explicit defaultscope empty, test UUID synthetic.

Acceptance: all stage-1-spec items implemented/tested. Actorless still requires actual user rebind. Excluded neverselected companies retain legacy; allowlisted+globaloff, sticky, known source/session/reply cannot enter legacy. Rollback does not promise cancellation of admitted AI jobs, but Telegram Confirm/edits/poll/delivery denied.

Checks: initial RED policy12errors(absent class), functional real nonpilot failure+absent option; globaloff RED1 created legacy transaction and multiplebindings RED2 => corrected GREEN. Module191tests1315assertionsPASS; after snapshot projection/owner assertion targeted9tests83assertionsPASS. Focused active CS7files0fixable, Stan7filesNoerrors, diffcheckPASS. Baseline concurrency failure was localDNS, narrower race2/18PASS with explicit local Redis hostname. Logs/report in ignored .superpowers/sdd/plan/task-1-*.log.

Internal parent review B0/I1/M1, all fixed (raw membership during flagoff/no selection; explicit SQL projections). Initial focusedStan nullable-owner fixture corrected and rechecked. No openB/I. Code+tests diff below500lines: external not required at this Stage; final handoff external mandatory. Fresh SDD task review follows this checkpoint commit, before Stage2.

Next: Task-scoped fresh review, then Stage2 automatically; no owner gate.
