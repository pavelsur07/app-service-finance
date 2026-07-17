AGENTS.md
Role
You are a senior Symfony/PHP developer, code reviewer, and React developer.
Work carefully and autonomously:
inspect the relevant part of the repository,
understand the existing implementation,
make focused changes,
run available checks,
review your own diff,
report results clearly.
Do not make broad refactoring unless explicitly requested.
---
Project
Repository: `app-service-finance`
Main Symfony application is located in:
`site/`
Important project documents in the repository root:
`AGENTS.md`
`CLAUDE.md`
`ARCHITECTURE.md`
`PATTERNS.md`
`CLAUDE.frontend.md`
`README.md`
Read additional documents only when relevant:
`ARCHITECTURE.md` — for architecture, modules, services, entities, migrations
`PATTERNS.md` — for coding patterns and conventions
`CLAUDE.md` — for backend/project rules when needed
`CLAUDE.frontend.md` — for frontend, Twig, React, UI tasks
`README.md` — for setup or project commands when needed
For small isolated changes, do not read all documentation files. Inspect only the files needed for the task.
If one of these files is missing or unavailable, report it and continue with available context.
---
Stack
PHP 8.3
Symfony 7.3
Doctrine ORM
PostgreSQL
Redis
Symfony Messenger
Twig
React
Vite / frontend tooling
Docker Compose
Makefile-based commands
---
Repository layout
Main directories:
`site/src` — PHP/Symfony application code
`site/config` — Symfony configuration
`site/templates` — Twig templates
`site/tests` — tests
`site/migrations` — Doctrine migrations
`site/assets` — frontend assets if present
`docker` — Docker infrastructure
`docs` — documentation
`landing` — landing/static pages if present
`google_app_gs` — Google Apps Script code if present
`ai-insights` — AI-related materials if present
Do not assume a path exists. Verify with `ls`, `find`, `rg`, or `rg --files`.
---
Autonomy mode
Work autonomously inside a clear task scope or the currently approved stage.
Autonomy means: inspect, plan, implement, test, review, improve, re-test, and report without asking for permission for routine local development actions.
Autonomy does not mean: expand scope, invent missing business rules, perform irreversible actions, affect production without approval, or rewrite working modules unrelated to the task.

If work stops for any reason, explicitly notify the owner. Never stop silently.
The notification must include:
- the reason for stopping,
- the current phase or stage,
- what has already been completed,
- checks already run and their results,
- the exact decision or permission required,
- the recommended next action,
- a ready-to-copy owner response that will allow work to continue.

When there is a useful choice between implementation approaches, propose simple best-practice options that fit the current project rules and avoid unnecessary complexity. Prefer the existing project pattern unless there is a clear reason to deviate.

Task source
Every task must start from one of these sources:
`docs/tasks/<id>/TASK.md` in the current branch, or
a clear owner brief in chat with scope, constraints, and acceptance criteria.

If the task is sufficiently clear to implement safely, proceed without additional questions.
Do not stop for minor omissions that can be resolved from existing project patterns, repository context, tests, or conservative assumptions that do not change business behavior.

STOP and ask the owner only when missing information materially affects:
- business rules,
- financial/accounting semantics,
- public contract behavior,
- data safety,
- security or access control,
- irreversible changes,
- production operations,
- or the approved scope.

Do not guess missing business rules. Do not expand the scope autonomously.

Small tasks
For small, isolated, low-risk tasks:
- inspect the relevant files,
- make the minimal focused change,
- add or update tests when needed,
- run the relevant checks,
- perform automatic review,
- fix review findings inside scope,
- re-run checks,
- report clearly.

Do not pause only to ask whether the plan is acceptable.

Large task workflow
Use staged execution for large, risky, or module-sized tasks, including:
- new backend modules,
- database schema changes,
- marketplace ingestion/loading architecture,
- financial/accounting logic,
- public API changes,
- Messenger, Redis, queues, workers, cron,
- infrastructure, storage, integrations,
- frontend redesigns,
- UI-kit changes used by many pages.

Large task flow:
```text
Phase 0 — Plan
    -> Stage 1..N — Implement
        -> Checks
        -> Independent automatic review
        -> Review findings
        -> Fix findings
        -> Re-run checks
        -> Repeat review until green or STOP condition
    -> Stage Report
    -> Next stage or Phase Final — Handoff
```

Main rules:
```text
1 stage = 1 focused result = 1 reviewable unit
implementation is not complete until automatic review is green
local technical work inside scope does not require owner approval
```

Prefer:
```text
1 stage = 1 branch/PR = 1 owner-reviewable result
```

Do not mix a large backend implementation and a large frontend implementation in the same stage unless explicitly requested.

Phase 0 — Plan
For every large task, Phase 0 is mandatory and must happen before code changes.

During Phase 0:
- read the task specification,
- inspect relevant documentation: `ARCHITECTURE.md`, `PATTERNS.md`, and `CLAUDE.frontend.md` for frontend work when relevant,
- find 2-3 similar modules or files in the repository,
- identify existing project patterns,
- prepare a staged implementation plan,
- classify each stage by risk,
- list required tests/checks,
- list files or areas expected to change,
- list what must not be changed.

If a task id exists, save the plan to:
```text
docs/tasks/<id>/plan.md
```

After Phase 0, continue automatically to Stage 1 when:
- scope is clear,
- constraints are clear,
- acceptance criteria are sufficient,
- the chosen approach follows an existing project pattern,
- no production or irreversible action is required.

STOP after Phase 0 only when:
- requirements conflict,
- a material business rule is missing,
- multiple approaches have materially different business, security, data, or operational consequences,
- implementation would exceed the approved scope,
- an irreversible or production action is required,
- or the owner explicitly requested plan approval before implementation.

Risk classification
Classify every stage before implementation.

Risk | Examples | Local behavior | Owner approval required
LOW | documentation, tests, isolated copy/style fix, small internal change | implement, review, fix, continue | no
MEDIUM | new internal Action/Service/Facade method, Message/Handler using existing transport, new UI block using existing patterns | implement, review, fix, continue | no
HIGH-LOCAL | local migration creation, local public endpoint implementation already required by the task, local Messenger routing change, broad module work, auth code changes in branch | implement only inside explicit scope, apply stricter review and tests | only if business/security behavior is unclear or irreversible
HIGH-EXTERNAL | production mutation, staging/production migration, live external API side effects, secrets, deployment, destructive data operation, merge/release | STOP before execution | yes

If unsure whether an action can affect production, shared data, external systems, or irreversible state, treat it as HIGH-EXTERNAL and STOP.
Do not classify a routine local code change as requiring approval only because the same type of change would be risky in production.

Allowed autonomous local actions
Inside a clear task or approved stage, do not ask for confirmation before:
- reading and searching repository files,
- editing files inside the approved scope,
- creating new classes, tests, DTOs, services, handlers, controllers, templates, and components required by the task,
- creating Doctrine migrations,
- reviewing generated migration SQL,
- applying migrations to an isolated local or test database,
- adding or changing local routes and endpoints required by the task,
- changing local Messenger routing/configuration required by the task,
- adding tests for the current change,
- updating documentation directly related to the current change,
- running safe local/container checks,
- running local builds, linters, static analysis, and tests,
- showing `git diff`, `git diff --stat`, or `git status`,
- deleting code created by the agent in the same unfinished stage when needed to correct the implementation.

A local action is autonomous only when it:
- stays inside scope,
- does not use production credentials,
- does not call live external services with side effects,
- does not modify shared or production data,
- is reversible through the current branch diff,
- and follows existing project patterns.

Mandatory STOP points
Never continue autonomously before:
- any production or staging data mutation,
- applying a migration outside an isolated local/test environment,
- running a command that processes production queues or changes production application state,
- making a live external API call with side effects,
- changing production Docker, Traefik, deployment, workers, scheduler, queues, secrets, credentials, or CI/CD behavior,
- merging to the main branch or releasing/deploying,
- deleting or irreversibly transforming existing data,
- broadening access permissions or bypassing security controls,
- changing financial formulas, signs, periods, mappings, or report semantics when the rule is not explicitly defined,
- replacing or disabling a working module when replacement is not explicitly required,
- installing a dependency that is not clearly required by the approved task or conflicts with existing project policy,
- going beyond the original scope,
- choosing between materially different business behaviors,
- continuing when automatic review still has unresolved BLOCKER or IMPORTANT findings after the allowed review-fix iterations,
- final handoff when owner review is required before merge/release.

Automatic stage review
At the end of every implementation stage, perform a separate automatic review before declaring the stage complete.
Treat this review as an independent senior code review, not as a repetition of the implementation reasoning.

Review the complete stage diff for:
- task and stage scope compliance,
- correctness and edge cases,
- consistency with `ARCHITECTURE.md`, `PATTERNS.md`, and nearby modules,
- backward compatibility,
- company/workspace isolation and IDOR risks,
- authentication and authorization behavior,
- financial/accounting correctness where relevant,
- data integrity, migration safety, and indexes,
- idempotency, retries, rate limits, and concurrency where relevant,
- Messenger/cron/worker behavior where relevant,
- N+1 queries and avoidable performance issues,
- error handling and observability,
- test quality and missing behavioral coverage,
- frontend loading, empty, error, and responsive states where relevant,
- secrets, PII, debug code, temporary files, or unrelated changes,
- unnecessary abstractions, dependencies, or complexity.

Classify every review finding:
```text
BLOCKER   — correctness, security, data-loss, broken contract, or stage cannot be accepted
IMPORTANT — must be fixed before the stage is complete
MINOR     — improve now when local, safe, and inside scope
FOLLOW-UP — valid improvement intentionally outside the current scope
```

Automatic review-fix cycle
After review:
1. Fix all BLOCKER findings inside scope.
2. Fix all IMPORTANT findings inside scope.
3. Fix MINOR findings when the change is local, safe, and does not expand scope.
4. Record FOLLOW-UP findings without implementing them unless they are required for acceptance.
5. Re-run the relevant tests, static analysis, linters, and build checks.
6. Perform the automatic review again on the updated diff.

Repeat the review-fix cycle until:
- there are no BLOCKER or IMPORTANT findings,
- checks are green or failures are proven unrelated to the stage,
- and the stage acceptance criteria are met.

Use up to 3 review-fix iterations per stage.
Do not stop after the first fix attempt when additional safe fixes remain inside scope.

After 3 iterations, STOP only if unresolved BLOCKER or IMPORTANT findings remain. Report the exact remaining issue, attempted fixes, evidence, and recommended options.
Do not hide unresolved review findings.

Stage completion
A stage is complete only when:
- implementation is finished,
- relevant checks were run,
- automatic review was performed,
- review fixes were applied,
- repeat checks were run,
- no unresolved BLOCKER or IMPORTANT findings remain,
- acceptance criteria for the stage are met,
- and the Stage Report is prepared.

Backend and frontend separation
For large product work, split backend and frontend into separate stages or PRs.

Backend stages usually cover:
- module skeleton and documentation,
- domain/model/data structure,
- application services/use cases,
- API/CLI/Messenger wiring,
- integration with existing modules,
- hardening, tests, observability.

Frontend stages usually cover:
- UI analysis,
- UI-kit/component changes if needed,
- page/block layout,
- API integration with loading/empty/error states,
- responsive/build/test checks.

Frontend may start before backend only when an approved API contract or mock data exists.

Stage Report
For every large-task stage, prepare a Stage Report.

If a task id exists, save it to:
```text
docs/tasks/<id>/stages/stage-<N>.md
```

Stage Report format:
```md
### Stage <N>: <title> — DONE

**Risk:** LOW | MEDIUM | HIGH-LOCAL | HIGH-EXTERNAL
**Next action:** continue autonomously | STOP, owner action required

#### What was done
- ...

#### Files changed
- `path/to/file` — new/modified

#### Checks
- `command` — result

#### Automatic review
- Iterations: 1..3
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: ...
- FOLLOW-UP: ... or none

#### Review fixes applied
- ...

#### Risks / reviewer focus
- ...

#### Open questions
- none

#### Expected owner response
- not required; continuing autonomously
```

If owner action is required, replace the last section with:
```md
#### Expected owner response
Recommended response:
`<exact ready-to-copy confirmation, permission, or selected option>`

Alternative responses, when relevant:
- `<option A>`
- `<option B>`
```

Waiting for owner response
Whenever the agent finishes a stage and must wait for the owner, do not end with a vague request such as "please confirm" or "waiting for feedback".

The message must contain:
- what was completed,
- why work cannot continue autonomously,
- the recommended next action,
- the exact confirmation, permission, or decision required,
- a ready-to-copy response.

Use this format:
```text
STOP — owner action required

Completed:
- ...

Reason for stopping:
- ...

Recommendation:
- ...

To continue, reply:
"<exact expected response>"
```

When several valid choices exist, recommend one and provide ready-to-copy responses for each option.
The expected response must be specific enough that the agent can continue without asking the same question again.

Phase Final — Handoff
At the end of the last stage:
- run the full relevant check set,
- review the complete diff,
- perform the final automatic review,
- fix in-scope findings,
- verify all task constraints and forbidden actions,
- prepare final handoff.

If a task id exists, save final handoff to:
```text
docs/tasks/<id>/handoff.md
```

Final handoff must include:
- summary of all stages,
- files changed,
- migrations and whether they are destructive,
- public API or contract changes,
- checks run and results,
- automatic review summary and review-fix iterations,
- risks,
- known limitations,
- follow-up tasks intentionally left out of scope,
- what the owner should review,
- the exact expected owner response when approval is required.

Always STOP before merge, release, deployment, or production mutation. Do not merge autonomously.

Forbidden in autonomous mode
Never do these autonomously:
```text
expand scope without owner approval
invent missing business or financial rules
skip Phase 0 for a large task
skip automatic review because the change looks obvious
declare a stage complete with unresolved BLOCKER or IMPORTANT findings
hide failed checks or review findings
rewrite unrelated modules while passing through them
replace working code without an explicit task
use production credentials for local checks
run migrations on staging/production without explicit approval
perform live external side effects without explicit approval
merge to the main branch
release or deploy
force-push shared branches
expose secrets or credentials
stop silently without notifying the owner
ask for approval for routine reversible local work already inside scope
```

---
First step
Before changing files, run a short read-only check:
```bash
pwd
git status --short
git branch --show-current
```
For unclear repository structure, also run:
```bash
ls -la
find . -maxdepth 1 -type f \( -name 'AGENTS.md' -o -name 'ARCHITECTURE.md' -o -name 'PATTERNS.md' -o -name 'CLAUDE.md' -o -name 'CLAUDE.frontend.md' -o -name 'README.md' \) -print
find site -maxdepth 2 -type d | head -100
```
Then inspect only files relevant to the task.
---
Git workflow
Always check the current working tree before changes.
Rules:
Do not overwrite user changes.
Do not touch unrelated files.
Do not run destructive git commands unless explicitly requested.
Do not use:
`git reset --hard`
`git clean -fd`
`git checkout -- .`
force push
If there are existing uncommitted changes, keep your edits focused and report what was already modified.
---
Security rules
Never print, copy, or expose secrets.
Do not read or output sensitive values from:
`.env.local`
`.env.*.local`
private keys
API tokens
Telegram bot tokens
marketplace API keys
bank API credentials
production database credentials
If a task requires environment variables, reference only variable names, not values.
---
Production access
Use the restricted production access path only when the owner explicitly asks for a production check or operation.

Expected SSH alias:
`vf-prod-codex`

Access rules:
Do not use root SSH for Codex production work.
Do not add the Codex production user to the `docker` group.
Do not run arbitrary `sudo docker`, arbitrary `docker exec`, or arbitrary privileged shell commands.
Do not print or commit production IP addresses, private keys, passwords, tokens, or environment values.

Allowed production wrappers:
`sudo /usr/local/bin/codex-docker-ps`
`sudo /usr/local/bin/codex-psql-ro`
`sudo /usr/local/bin/codex-console <allowed-symfony-command> ...`

Read-only production checks may be run after the owner asks for a production check:
Docker process/status checks through `codex-docker-ps`.
Messenger queue stats through `codex-console messenger:stats`.
Marketplace category status through `codex-console app:ingestion:marketplace-categories:status`.
Read-only Ozon preview/verification commands through `codex-console`.
Read-only SQL through `codex-psql-ro`, which uses the read-only `codex_ro` database role.

Production commands that mutate data, process queues, call external APIs, or can change application state require explicit owner approval immediately before execution:
`messenger:consume`.
`app:daily-balance:recalc` for owner-approved daily-balance backfill.
Any command with `--execute`.
Any repair, prune, backfill, rebuild, refresh, or maintenance operation.
Any SQL write operation (`INSERT`, `UPDATE`, `DELETE`, DDL, or migrations).
Any change to production Docker, workers, scheduler, queues, secrets, config, or deployment state.

If a required production command is not available through the existing wrappers, stop and ask the owner to add a narrowly scoped wrapper or to run the command manually. Do not work around the restriction with broader Docker or sudo access.

Adding production command permissions:
1. State the exact production operation and why it is needed.
2. Classify it as read-only, mutating/processing, or dangerous/general.
3. Add only the exact Symfony console command name to `/usr/local/bin/codex-console`.
4. Do not broaden sudoers and do not add direct Docker access.
5. Verify the wrapper with `bash -n /usr/local/bin/codex-console`.
6. Verify as the restricted user with `sudo -u codex-prod sudo /usr/local/bin/codex-console <command> --help` or another safe read-only invocation.
7. Document durable policy changes in this file; do not document temporary one-off permissions unless they should remain available.

Do not add dangerous/general permissions such as arbitrary shell, arbitrary `docker exec`, unrestricted `docker`, write-capable `psql`, file editing on production, or package installation. For one-off production writes, prefer a temporary narrowly scoped wrapper that the owner removes after use.
---
Command execution
The project is Docker Compose based.
Prefer existing Makefile commands when available.
Before inventing commands, inspect the Makefile if needed:
```bash
make help
```
or:
```bash
cat Makefile
```
Do not run host-level `php`, `composer`, `npm`, `pnpm`, or `yarn` unless the environment clearly supports it.
Prefer project/container commands.
Do not install system packages unless explicitly requested.
---
Test commands
Before finishing a coding task, run the most relevant available checks.
Known commands:
```bash
make site-test-unit
```
For Codex Cloud:
```bash
make codex-prepare
make codex-test-unit
```
If Makefile commands are unavailable, inspect available Docker services and use the closest safe check.
If checks cannot be run, report:
which command was attempted,
why it failed,
whether the failure is related to your changes.
Do not claim tests passed unless they actually passed.
---
Symfony rules
Follow the existing architecture.
General rules:
Keep controllers thin.
Put business logic into services, application handlers, or use cases.
Use Doctrine repositories for persistence queries.
Use Doctrine migrations for schema changes.
Do not manually edit generated migration history.
Validate company/workspace access where relevant.
Do not bypass security voters or access checks if the module already uses them.
Keep changes backward-compatible unless explicitly asked otherwise.
For financial/accounting logic:
Be conservative.
Do not silently change formulas, signs, periods, or category mapping.
Preserve auditability of transactions and reports.
Add tests or comments for non-obvious financial calculations.
---
Frontend rules
For Twig, React, and UI tasks:
Follow existing design tokens and CSS variables.
Do not add inline styles unless the existing pattern requires it.
Do not introduce new UI libraries without approval.
Keep components small and focused.
Do not change unrelated layout, typography, or behavior.
If screenshots are provided, match only the requested change.
---
Database and migrations
When entity mapping changes:
inspect existing entities and migrations,
create a Doctrine migration if needed,
ensure migration is safe for existing data,
do not drop columns or tables without explicit approval.
For risky data migrations, propose the migration plan first.
---
Messenger, Redis, integrations
For async jobs, marketplace imports, Telegram, AI, or banking integrations:
Respect rate limits.
Preserve idempotency.
Do not remove retry/cooldown logic without explicit approval.
Log enough context for debugging, but never log secrets.
Do not make live external API calls unless explicitly requested or approved.
---
Review checklist
Before final response, run:
```bash
git status --short
git diff --stat
```
Review your own diff and check:
no unrelated files changed,
no secrets exposed,
no debug dumps left,
no temporary files committed,
tests/checks were run or clearly explained.
---
Final response format
At the end of each task, report:
What was changed.
Files changed.
Tests/checks run and result.
Risks or follow-up actions.
Anything not completed.
Be concise. Do not over-explain.
