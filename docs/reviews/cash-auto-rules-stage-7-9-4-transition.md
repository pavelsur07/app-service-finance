### Stage 7.9.4: Resume Stage 7.6 cutover — TRANSITION OPEN

**Risk:** HIGH
**Next action:** start Stage 7.6.2 as a separate reviewable unit; do not include Stage 7.6.4 imports in the same PR

#### What was closed

- Stage 7.9.2 production configuration gate is complete:
  - dry-run found 74 assignable active `PROJECT_GENERAL` rules;
  - execute updated 74 rules after owner approval;
  - follow-up dry-run found 0 candidates;
  - read-only SQL found 0 active `PROJECT_GENERAL` rules with empty `responsibility_center_id`.
- Stage 7.9.3 planner is merged, deployed, and accepted with controlled read-only preview.
- Production preview for rule `Перевод на карту` showed:
  - 20 July 2026 rows would change;
  - only `responsibilityCenterId` changes from empty to `CFO_GENERAL`;
  - category, project, and counterparty changes are 0;
  - conflicts are 0.
- No historical range, recalculation, import batch, or transaction mutation was executed by Codex during acceptance.

#### Stage 7.9.4 decision

- The auto-rule gate no longer blocks Stage 7.6 writer work.
- Resume with Stage 7.6.2 first: core Cash create/update and `CashFacade` command contract.
- Keep Stage 7.6.3 manual UI and Stage 7.6.4 import cutover as separate HIGH-risk stages.

#### Self-review

- [x] Scope limited to transition documentation
- [x] No code, migration, production write, history run, import run, queue change, or recalculation
- [x] Stage 7.9.2 and Stage 7.9.3 gates recorded
- [x] Stage 7.6.4 import cutover remains gated

#### Checks

- Documentation-only update; no runtime checks required.

#### Risks / reviewer focus

- Stage 7.6.2 changes financial write semantics and must stay isolated from import cutover.
- Stage 7.6.4 must not start until Stage 7.6.2/7.6.3 acceptance confirms defaulted transactions still classify correctly through configured auto-rules.

#### Open questions

- none.
