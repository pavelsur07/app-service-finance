# Cash auto rules — current Stage 7 status

## Current linear plan

No jumping between old reports. Work proceeds in this order:

1. **Stage A — Stage 7.8.1 post-merge production acceptance:** DONE.
2. **Stage B / Stage 7.11 — consolidated cleanup/follow-ups:** checking/reviewing in branch `agent/stage7-linear-closure`.
3. **Stage C / Stage 7.8.2 — Cashflow Project × ЦФО matrix:** next product stage after Stage B is merged and accepted.
4. **Stage D — final Stage 7 closure:** final handoff after Stage C.

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

## Open work

### Stage B / Stage 7.11

Cleanup and hardening only:

- stale next-action/status loops removed from Stage 7 docs;
- P&L daily-total old-schema runtime detection removed;
- raw P&L debug ЦФО filter added;
- failed Messenger diagnostics recorded read-only without mutating queue state.

### Stage C / Stage 7.8.2

Product analytics:

- Cashflow Project × ЦФО matrix;
- ЦФО → projects;
- project → ЦФО.

### Stage D

Final Stage 7 handoff and closure.

## Explicitly not open inside Stage 7.11

- Production import smoke — separate production mutation approval if ever needed.
- Historical backfill/recalculation — forbidden unless separately scoped.
- Queue consume/retry/delete — forbidden in cleanup.
- Cashflow selected-ЦФО second-query optimization — only after measured performance need.
- Composite DB guard for pair-removal race — separate schema-hardening Phase 0 if needed.
