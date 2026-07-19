# Cash auto rules — current Stage 7 status

## Current linear plan

No jumping between old reports. Stage 7 is closed:

1. **Stage A — Stage 7.8.1 post-merge production acceptance:** DONE.
2. **Stage B / Stage 7.11 — consolidated cleanup/follow-ups:** DONE, merged as #2199.
3. **Stage C / Stage 7.8.2 — Cashflow Project × ЦФО matrix:** DONE, merged as #2201. Original stacked PR #2200 was closed after its base branch was deleted by #2199 merge.
4. **Stage D — final Stage 7 closure:** DONE.

## Closed stages

- Stage 7.1 — baseline/Phase 0: DONE.
- Stage 7.2 — Company ЦФО master data and system pair: DONE, production accepted.
- Stage 7.3 — Company actions/services: DONE.
- Stage 7.4 — protected ЦФО UI: DONE, production accepted.
- Stage 7.5 — fact schema expansion: DONE, production accepted.
- Stage 7.6.1 — Cash scalar mapping/read contract: DONE, production accepted.
- Stage 7.6.2 / 7.6.3 — core/manual Cash writer cutover: DONE, merged/deployed.
- Stage 7.6.4 — import cutover: DONE, merged/deployed; production import smoke remains excluded because it mutates production.
- Stage 7.7.1 — Finance scalar mapping/pair validation: DONE.
- Stage 7.7.2 — document writer propagation: DONE.
- Stage 7.7.3 — P&L daily totals Project × ЦФО key: DONE, migration accepted.
- Stage 7.7.4 — P&L read-side ЦФО filter: DONE.
- Stage 7.8.1 — ДДС cashflow ЦФО filter: DONE, merged as #2198 and accepted by Stage A.
- Stage 7.9 — ЦФО auto-rule target and production configuration gate: DONE.
- Stage 7.10 — hardening/read-only production acceptance: DONE.

## Final closure

### Stage B / Stage 7.11

Cleanup and hardening only:

- stale next-action/status loops removed from Stage 7 docs;
- P&L daily-total old-schema runtime detection removed;
- raw P&L debug ЦФО filter added;
- failed Messenger diagnostics recorded read-only without mutating queue state.
- merged to `master` as #2199.

### Stage C / Stage 7.8.2

Product analytics:

- Cashflow Project × ЦФО matrix added to existing report/API;
- ЦФО → projects;
- project → ЦФО.
- merged to `master` as #2201.

### Stage D

Final Stage 7 handoff and closure is complete.

## Explicitly not done by Stage 7

- Production import smoke — separate production mutation approval if ever needed.
- Historical backfill/recalculation — forbidden unless separately scoped.
- Queue consume/retry/delete — forbidden in cleanup.
- Cashflow selected-ЦФО second-query optimization — only after measured performance need.
- Composite DB guard for pair-removal race — separate schema-hardening Phase 0 if needed.
