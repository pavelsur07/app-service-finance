# Checkpoint: restore automatic master deploy

- Phase 0: DONE.
- Branch: `agent/restore-master-auto-deploy`.
- Stage base: `d215bf90a12462e0ef1111908ea4ce3a15b42277`.
- Instruction hashes:
  - `AGENTS.md`: `07a7a9f66507e979ee3474513991371b439ecbdbce18605cb0bb715077e9c239`
  - `CLAUDE.md`: `73f70bc02a51684bbe9db99ec4644fbcfc6b3ef6ef934d985bf80a448f13bf75`
- Historical reference: `0a4e86a2` (automatic master deploy), `7b7d4de4` (temporary manual gate).
- Baseline: both `schema-ready` and `deploy` accepted only manual `workflow_dispatch` with `production_action=deploy`.
- Work item 1.1: DONE — `schema-ready` accepts deployable pushes to `master` and the existing manual deploy action, with all prerequisite checks retained.
- Work item 1.2: DONE — `deploy` accepts the same event set and still requires successful `schema-ready`.
- Work item 1.3 checks:
  - PyYAML syntax/structure and exact guarded event assertions: green;
  - `migrations` and `category-plan` conditions unchanged from Stage base: green;
  - `git diff --check`: green.
- Internal Stage review: green; no BLOCKER or IMPORTANT findings.
- External read-only review: `REVIEW_GREEN`; no BLOCKER or IMPORTANT findings.
- Stage 1: DONE locally; awaiting Stage commit, push and Draft PR CI.
- Production actions performed: none.
