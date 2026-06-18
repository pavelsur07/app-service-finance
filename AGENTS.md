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
Work autonomously, but only inside a clear task scope or inside the currently approved stage.
Autonomy means: inspect, plan, edit, test, self-review, and report without asking for every small step.
Autonomy does not mean: expand scope, skip review gates, rewrite working modules, or implement a large task end-to-end in one unsafe pass.
If work stops for any reason (mandatory STOP point, blocker, failed check that cannot be fixed safely, missing input, or environment issue), explicitly notify the owner with the reason, current stage, completed work, and the next possible action.
When there is a useful choice between implementation approaches, propose simple best-practice options that fit the current project rules and do not add unnecessary complexity. Prefer the existing project pattern unless there is a clear reason to deviate.
Task source
Every task must start from one of these sources:
`docs/tasks/<id>/TASK.md` in the current branch, or
a clear owner brief in chat with scope, constraints, and acceptance criteria.
If there is no clear task specification, STOP and ask for it.
Do not guess missing business rules. Do not expand the scope autonomously.
Small tasks
For small, isolated, low-risk tasks:
inspect the relevant files,
make the minimal focused change,
add or update tests when needed,
run the relevant checks,
review the diff,
report clearly.
Do not pause only to ask whether the plan is acceptable.
Large task workflow
Use staged execution for large, risky, or module-sized tasks, including:
new backend modules,
database schema changes,
marketplace ingestion/loading architecture,
financial/accounting logic,
public API changes,
Messenger, Redis, queues, workers, cron,
infrastructure, storage, integrations,
frontend redesigns,
UI-kit changes used by many pages.
Large task flow:
```text
Phase 0 — Plan  ->  Stage 1..N — Execute by stages  ->  Phase Final — Handoff
                         ^
              after every stage: self-review + Stage Report
              high-risk stage: STOP for owner review
              red self-review: fix once or STOP
```
Main rule:
```text
1 stage = 1 focused result = 1 reviewable unit
```
Prefer:
```text
1 stage = 1 branch/PR = 1 owner review
```
Do not mix a large backend implementation and a large frontend implementation in the same stage unless explicitly requested.
Phase 0 — Plan
For every large task, Phase 0 is mandatory and must happen before code changes.
During Phase 0:
read the task specification,
inspect relevant documentation: `ARCHITECTURE.md`, `PATTERNS.md`, and `CLAUDE.frontend.md` for frontend work when relevant,
find 2-3 similar modules or files in the repository,
identify existing project patterns,
prepare staged implementation plan,
classify each stage by risk,
list required tests/checks,
list files or areas expected to change,
list what must not be changed.
If a task id exists, save the plan to:
```text
docs/tasks/<id>/plan.md
```
After Phase 0, STOP for owner approval unless the owner explicitly asked to execute a specific already-scoped stage.
Risk classification
Classify every stage before implementation.
Risk	Examples	Behavior after green self-review
LOW	documentation, tests, small internal refactor inside one service/action, isolated UI copy/style fix	continue autonomously
MEDIUM	new internal action/service/facade method, new message/handler without new transport, new UI block using existing patterns	continue autonomously and save Stage Report
HIGH	database migration, public API change, auth/RBAC/Voter change, dependency install, legacy-zone change, deletion, new Messenger transport, cron/worker change, production config, broad UI-kit change	STOP for owner review before continuing
If unsure, classify the stage as HIGH.
Mandatory STOP points
Never continue autonomously without owner review before:
creating or running a database migration,
dropping or renaming a table/column,
changing public API URL, response fields, status codes, or types,
adding a public endpoint,
changing auth, Security, roles, voters, or company access rules,
changing `config/packages/messenger.yaml`, transports, workers, cron, or queue routing,
installing composer/npm/system dependencies,
deleting a file, class, method, endpoint, database field, or table,
changing production Docker, Traefik, deployment, secrets, or CI/CD behavior,
replacing or disabling a working module,
changing existing financial formulas, signs, periods, mappings, or report semantics,
connecting experimental ingestion/import code to production jobs,
making live external API calls,
going beyond the original scope,
continuing after a self-review problem that was not fixed in one iteration,
final handoff.
Allowed autonomous actions
Inside a clear task or approved stage, do not ask for confirmation before:
reading files,
searching with `rg`, `find`, `grep`, or similar tools,
editing files inside the approved scope,
adding tests for the current change,
updating documentation directly related to the current change,
running safe local/container checks,
showing `git diff`, `git diff --stat`, or `git status`.
Backend and frontend separation
For large product work, split backend and frontend into separate stages or PRs.
Backend stages usually cover:
module skeleton and documentation,
domain/model/data structure,
application services/use cases,
API/CLI/Messenger wiring,
integration with existing modules,
hardening, tests, observability.
Frontend stages usually cover:
UI analysis,
UI-kit/component changes if needed,
page/block layout,
API integration with loading/empty/error states,
responsive/build/test checks.
Frontend may start before backend only when an approved API contract or mock data exists.
Stage self-review
At the end of every stage, perform self-review before continuing.
Check:
scope compliance: no out-of-scope changes,
no broad refactoring,
no unrelated files changed,
project patterns followed,
company/workspace access respected where relevant,
no IDOR-prone repository access where relevant,
no secrets, tokens, passwords, or PII exposed,
no debug code left (`dump`, `dd`, `var_dump`, console noise),
no N+1 introduced in list screens or queries,
migrations/indexes are safe and intentional if present,
tests/checks were added or updated when needed,
documentation was updated when the stage changed architecture, public API, Facade, Enum, Entity, commands, or operational behavior.
If self-review is red, fix the issue once if it is clearly inside scope. If not fixed after one iteration, STOP and report.
Stage Report
For every large-task stage, prepare a Stage Report.
If a task id exists, save it to:
```text
docs/tasks/<id>/stages/stage-<N>.md
```
Stage Report format:
```md
### Stage <N>: <title> — DONE

**Risk:** LOW | MEDIUM | HIGH
**Next action:** continue autonomously | STOP, owner review required

#### What was done
- ...

#### Files changed
- `path/to/file` — new/modified

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated or N/A

#### Checks
- `command` — result

#### Risks / reviewer focus
- ...

#### Open questions
- none
```
Phase Final — Handoff
At the end of the last stage:
run the full relevant check set,
review the complete diff,
verify all task constraints and forbidden actions,
prepare final handoff.
If a task id exists, save final handoff to:
```text
docs/tasks/<id>/handoff.md
```
Final handoff must include:
summary of all stages,
files changed,
migrations and whether they are destructive,
public API or contract changes,
checks run and results,
risks,
known limitations,
follow-up tasks intentionally left out of scope,
what the owner should review.
Always STOP after final handoff. Do not merge autonomously.
Forbidden in autonomous mode
Never do these autonomously:
```text
expand scope without owner approval
skip Phase 0 for a large task
skip self-review because the change looks obvious
continue after a HIGH-risk stage without owner review
commit an unfinished or red stage
rewrite unrelated modules while passing through them
replace working code without an explicit task
run migrations on staging/production
merge to the main branch
force-push shared branches
expose secrets or credentials
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
