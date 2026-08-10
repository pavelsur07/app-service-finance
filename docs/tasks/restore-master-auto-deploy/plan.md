# Restore automatic master deploy

## Context

- Task base: `d215bf90a12462e0ef1111908ea4ce3a15b42277`.
- Commit `0a4e86a2` restored automatic deploy after deployable pushes to `master`.
- Temporary rollout commit `7b7d4de4` later restricted both `schema-ready` and `deploy` to manual `workflow_dispatch`.
- `AGENTS.md`, `CLAUDE.md` and `docs/maintenance/production-logging.md` still define automatic deploy after an explicitly approved merge to `master`.
- The current schema readiness check must remain in the path before every automatic or manual deploy.

## Stage 1: Restore guarded automatic deploy

Risk: HIGH-LOCAL
owner_gate: yes
release_candidate: yes
independently_deployable: yes
stage_base_commit: `d215bf90a12462e0ef1111908ea4ce3a15b42277`

Definition of Done:

- a deployable `push` to `master` may run `schema-ready` after build, empty-DB migration and API type checks succeed;
- `deploy` may run automatically only after successful `schema-ready` on `master`;
- manual `production_action=deploy` remains available and follows the same guard;
- pull requests, `workflow_dispatch` with another action, documentation-only pushes and failed prerequisites cannot deploy;
- manual `migrations` and `category-plan` gates remain unchanged;
- workflow syntax and exact event conditions are checked;
- internal review and external read-only review end green;
- changes are delivered only through a Draft PR; merge and production deploy are excluded.

Work items:

- 1.1 — extend `schema-ready` to approved automatic `push` and manual deploy events.
- 1.2 — extend `deploy` to the same event set while retaining its dependency on `schema-ready`.
- 1.3 — verify the workflow conditions, document the Stage and prepare the Draft PR.

Stage checks:

- exact static assertions for automatic/manual deploy conditions and unchanged manual gates;
- YAML/workflow validation available in repository tooling or GitHub Actions;
- `git diff --check`;
- internal independent review of the complete Stage diff;
- external read-only Claude review to `REVIEW_GREEN`;
- Draft PR CI status.

Reviewer focus:

- no deploy from pull requests or unrelated manual actions;
- schema readiness cannot be bypassed;
- migration/category gates and secrets remain unchanged;
- no production action occurs before the explicit Release/Production Gate.

## Gates

- Release Gate: owner decision about Ready and merge after the Draft PR is green.
- Production Gate: because merge to `master` will again trigger deploy automatically, a future merge instruction must explicitly approve both merge and its automatic production deploy.
- This task does not authorize merge, workflow dispatch, migration, queue processing, write smoke or any production mutation.

## Out of scope

- redesigning the deployment workflow;
- changing build matrices, images, secrets, deploy commands or container order;
- changing migration and category rollout behavior;
- production verification or repair.
