# Release: PR с миграцией

> Когда читать: PR несёт Doctrine-миграцию — до запроса «merge and deploy»
> на handoff. Триггер задан в `AGENTS.md` §3.2.

A merge into `master` deploys automatically after the required checks — but a
PR carrying a migration does **not** deploy on the standard approval.
Production migrations are a separate, manually dispatched action, and the
push-triggered deploy refuses to run until production's schema is current:

| Workflow job | Trigger |
|---|---|
| `migrations` | `workflow_dispatch` with `production_action: migrations` only |
| `Verify production schema is ready` | every push to `master`; fails while any migration is pending |
| `deploy` | only after that gate is green |

So a merge with a pending migration lands the code and stops there, with the
deploy job skipped — and the gate then blocks every later deploy too, not just
that one.

## Request template

When the PR carries a migration, say so in the handoff request and ask for
both actions at once:

```text
Ready: PR #<n> "<title>" — the PR adds migration <Version...>: <reversible? data touched? backup needed?>.
Run the production migration and deploy?
Reply: "run the migration and deploy #<n>"
```

An irreversible migration or a destructive data change is named explicitly in
the request, with the backup or rollback plan. The owner decides with that fact
in front of them.

## Dispatch order

After approval, dispatch in order and check the schema between the two:

```bash
gh workflow run deploy.yml --ref master -f production_action=migrations
gh workflow run deploy.yml --ref master -f production_action=deploy
```

Data migrations (measurements, merges, post-deploy reconciliation) —
`docs/workflow/data-migrations.md`.
