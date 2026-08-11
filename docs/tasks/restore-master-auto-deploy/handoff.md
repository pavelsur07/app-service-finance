# Handoff: restore automatic master deploy

- Branch: `agent/restore-master-auto-deploy`.
- Base: `d215bf90a12462e0ef1111908ea4ce3a15b42277`.
- Executable change: two GitHub Actions conditions plus matching comments.
- Automatic path: deployable `push` to `master` → required checks and image build → `schema-ready` → rolling deploy.
- Manual path: `workflow_dispatch` with `production_action=deploy` → the same checks and rollout.
- Manual `migrations` and `category-plan` are unchanged and never run implicitly.
- Internal review and external review are green.
- Current gate: Draft PR only; merge and production deploy are not authorized by this task.
