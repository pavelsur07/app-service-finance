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

Instruction precedence
For Codex execution and STOP decisions, use this order:
1. The owner's explicit instruction in the current task or current chat.
2. The nearest `AGENTS.md` to the files being changed.
3. The repository-root `AGENTS.md`.
4. The approved task specification and ADRs.
5. `PATTERNS.md` and `ARCHITECTURE.md`.
6. `CLAUDE.md` and `CLAUDE.frontend.md` as supplementary implementation rules.
7. Existing code and general best practices.

`CLAUDE.md`, an old Stage Report, a risk label, or a generic phrase such as "owner-reviewable" must not introduce a manual STOP that is absent from the applicable `AGENTS.md` and current owner instruction.
Only an explicit owner gate in the current task, a mandatory STOP point below, or a real blocker can pause autonomous execution.
If documents conflict, follow the higher-priority source, preserve safety, record the conflict, and continue when no real STOP condition exists.
---
Stack
PHP — actual Docker/runtime configuration is the source of truth
Symfony 7.4 according to `site/composer.json`; `composer.lock` is authoritative for installed versions
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

Continuous autonomous execution
After receiving a sufficiently clear task or completing Phase 0 without a STOP condition, continue through implementation, verification, review, fixes, commit, push, and Draft PR without requesting additional owner confirmation.

At the start of a new or resumed work session, re-read the current repository copies of the root and applicable nested `AGENTS.md` files and the relevant `CLAUDE.md`. Do not rely on copies cached in conversation history or loaded before those files were updated.
Before a stage review or commit in a long-running session, check whether these instruction files changed and re-read them when they did.

The required autonomous sequence is:
1. Implement the current stage.
2. Run targeted and relevant broader checks.
3. Perform the internal independent review of the complete stage diff.
4. Fix all in-scope BLOCKER and IMPORTANT findings and safe in-scope MINOR findings.
5. Re-run checks and repeat the internal review until green.
6. Run the external read-only Claude Code CLI review of the complete stage diff.
7. Validate its findings; fix confirmed in-scope BLOCKER and IMPORTANT findings and safe in-scope MINOR findings.
8. Re-run checks, internal review, and external review until the external reviewer returns `REVIEW_GREEN`.
9. Prepare the Stage Report and checkpoint when applicable.
10. Commit only the files belonging to the current task.
11. Push the current task branch without force.
12. Create or update a Draft PR.
13. Provide the final report only after the Draft PR is ready, or report a real STOP condition.

Internal review, external Claude Code review, review fixes, repeated tests, local migration work, commit, non-force push of the task branch, and Draft PR creation are pre-authorized parts of an approved task. They are actions to perform, not proposed next steps.

Never end a runnable task with messages such as:
- "the next step is review",
- "after green review we can commit",
- "request a repeat review of the current diff",
- "now the owner needs to conduct/review the stage",
- "ready to push after confirmation",
- "can create a PR after approval".

The agent invokes both reviews itself. Never instruct the owner to request, start, conduct, or approve an internal or external review.
Perform those actions before reporting. Do not merge, release, deploy, mutate production, or perform another HIGH-EXTERNAL action without explicit owner approval.

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

Baseline before implementation
Before changing code, run the smallest relevant baseline check when practical.

Record:
- the command,
- the result,
- any pre-existing failures,
- whether the environment was sufficient to run the check.

After implementation, compare the final result with the baseline.
Do not attribute a pre-existing failure to the current stage.
Do not block a safe task only because an unrelated pre-existing check is red; document the evidence and continue with the checks relevant to the current scope.

Stage Definition of Done
Before implementing each stage, define explicit completion criteria.

The Definition of Done must state:
- expected observable behavior,
- acceptance checks,
- required tests,
- required documentation changes,
- operational or observability requirements when relevant,
- compatibility requirements,
- work explicitly excluded from the stage.

Do not start implementation of a large stage until its Definition of Done is clear enough to verify objectively.
For small tasks, the Definition of Done may be a short internal checklist.

Small tasks
For small, isolated, low-risk tasks:
- inspect the relevant files,
- make the minimal focused change,
- add or update tests when needed,
- run the relevant checks,
- perform internal automatic review,
- fix review findings inside scope,
- re-run checks,
- run external read-only Claude Code CLI review,
- validate its findings and repeat fixes, checks, and both reviews until `REVIEW_GREEN`,
- commit and push the task branch when the task is intended for delivery,
- create or update the Draft PR,
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
    -> Baseline
    -> Stage Definition of Done
    -> Stage 1..N — Implement
        -> Targeted checks
        -> Module checks
        -> Full relevant stage checks
        -> Internal independent automatic review
        -> Review findings
        -> Fix findings
        -> Re-run checks
        -> Repeat internal review until green
        -> External read-only Claude Code CLI review
        -> Validate and fix confirmed findings
        -> Repeat checks and both reviews until REVIEW_GREEN or a real STOP condition
    -> Stage Report + Checkpoint
    -> Commit + push task branch + create/update Draft PR
    -> Next stage or Phase Final — Handoff
```

Main rules:
```text
1 stage = 1 focused result = 1 reviewable unit
implementation is not complete until internal automatic review is green and external Claude Code review returns REVIEW_GREEN
local technical work inside scope does not require owner approval
both green reviews lead directly to commit, push, and Draft PR without another confirmation
```

Prefer:
```text
1 task/reviewable unit = 1 task branch = 1 Draft PR
stages inside that unit may be separate focused commits
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
- define the Definition of Done for each stage,
- list baseline and final tests/checks,
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
`HIGH`, "high-risk", legacy, finance-related, auth-related, or migration-related work performed only in the task branch is HIGH-LOCAL unless it actually requires a HIGH-EXTERNAL action or an unresolved material owner decision.
"Stage is HIGH-risk, therefore STOP for owner review" is not a valid STOP reason for local implementation.
Owner review before merge happens through the Draft PR after the agent has completed both reviews, commit, and non-force push.

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
- running the configured read-only Claude Code CLI review,
- committing only the current task changes after both reviews are green,
- pushing the current task branch without force,
- creating or updating a Draft PR for the current task,
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
- continuing when either review has a confirmed unresolved BLOCKER or IMPORTANT finding that cannot be fixed safely inside scope after root-cause analysis and a reasonable alternative approach,
- merging, releasing, deploying, or mutating production after final handoff.

Do not STOP merely because review found issues, tests failed, several repair iterations were needed, a local migration was created, or the next normal action is commit, push, or Draft PR creation.

Internal automatic stage review
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

Internal automatic review-fix cycle
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

Do not stop after the first fix attempt when additional safe fixes remain inside scope.
After 3 unsuccessful review-fix iterations, perform root-cause analysis, reconsider the implementation approach, and try a materially different safe approach inside scope.

STOP only when a BLOCKER or IMPORTANT finding cannot be fixed safely inside scope because of a real external blocker, missing material business decision, unavailable required access, conflict with unrelated owner changes, or a required HIGH-EXTERNAL action. Report the exact remaining issue, attempted fixes, evidence, and recommended options.
Do not hide unresolved review findings.

External Claude Code CLI review
After the internal automatic review is green, run a second independent review with the locally installed Claude Code CLI before a stage commit or final task commit.
This review is mandatory for code, configuration, migration, infrastructure, and frontend changes. For a documentation-only change with no executable behavior change, it may be recorded as `N/A — documentation only`.

The external reviewer is read-only and advisory:
- it must not edit files, change Git state, call external services, or create commits,
- it does not replace Codex tests or the internal automatic review,
- Codex must independently verify every finding against the task, repository, and project rules,
- a false positive may be rejected only with a recorded technical reason,
- confirmed BLOCKER and IMPORTANT findings must be fixed before commit,
- safe in-scope MINOR findings should be fixed; FOLLOW-UP findings are recorded without expanding scope.

Preflight:
```bash
command -v claude >/dev/null
claude auth status >/dev/null
```

Never print or persist the output of `claude auth status`; it may contain account and organization identifiers.
Do not ask the owner to confirm this review call: it is a pre-authorized, local, read-only step of the approved workflow.
The primary Codex agent must execute the configured `claude -p` command itself. It must not replace execution with a recommendation that the owner request a review.

Run from the repository root:
```bash
claude -p \
  --safe-mode \
  --permission-mode dontAsk \
  --effort high \
  --tools "Read,Glob,Grep,Bash" \
  --allowedTools \
    "Read" \
    "Glob" \
    "Grep" \
    "Bash(git status)" \
    "Bash(git status *)" \
    "Bash(git diff)" \
    "Bash(git diff *)" \
    "Bash(git log)" \
    "Bash(git log *)" \
    "Bash(git show)" \
    "Bash(git show *)" \
    "Bash(git rev-parse *)" \
    "Bash(git merge-base *)" \
  --disallowedTools \
    "Edit" \
    "Write" \
    "NotebookEdit" \
    "WebFetch" \
    "WebSearch" \
    "mcp__*" \
  --strict-mcp-config \
  --no-session-persistence \
  --max-turns 120 \
  --output-format text \
  "Perform an independent senior code review of the current task diff.

You are the external reviewer invoked by Codex. Do not invoke another Claude or reviewer process recursively. Do not edit files. Do not change Git state. Do not call external services. Do not read .env files, credentials, private keys, authentication data, or production dumps.

Read AGENTS.md, CLAUDE.md, the task specification available in the repository, and only the relevant sections of PATTERNS.md and ARCHITECTURE.md. Inspect git status, staged and unstaged changes, and all task-owned untracked files.

Review scope compliance, correctness, edge cases, backward compatibility, tenant isolation and IDOR, authorization, financial calculations and Money, transactions and idempotency, migrations and indexes, Messenger retries and concurrency, error handling and observability, performance and N+1, test quality, secrets and PII, and unnecessary complexity. Apply only the categories relevant to this diff.

Classify every finding as BLOCKER, IMPORTANT, MINOR, or FOLLOW-UP. For each finding provide file:line, evidence, impact, and a concrete fix.

If there are no BLOCKER or IMPORTANT findings, end with the exact line:
REVIEW_GREEN"
```

Do not use `--dangerously-skip-permissions`. Do not grant `Edit`, `Write`, unrestricted `Bash`, web, or MCP tools to the external reviewer.
Do not send secrets, environment values, production data, or authentication output in the prompt.
`--safe-mode` is required so project hooks, plugins, skills, MCP servers, auto-memory, and other local Claude customizations cannot introduce writes, external calls, or interactive prompts. The reviewer must still read `CLAUDE.md` explicitly as a repository file.

For a stage review, review the staged and unstaged changes plus task-owned untracked files before the stage commit.
For the final handoff after stage commits, Codex must determine the verified task base ref or base commit without fetching, append that exact value to the prompt, and require review of `<base>...HEAD` plus any remaining working-tree changes. Never silently assume `main` or `master`.

External review-fix cycle:
1. Capture the review result in the Stage Report or final handoff.
2. Verify every finding; record rejected findings and their reasons.
3. Fix confirmed in-scope findings according to their severity.
4. Re-run the relevant checks and the internal automatic review.
5. Run the external Claude Code review again on the updated complete diff.

Repeat until the final external review ends with the exact standalone line `REVIEW_GREEN` and no confirmed BLOCKER or IMPORTANT finding remains.
Do not treat a failed command, truncated output, permission denial, timeout, authentication error, or missing `REVIEW_GREEN` marker as a green review.

If the command fails, diagnose it and make one retry after a material correction. If Claude Code remains unavailable or unauthenticated, STOP as a real external reviewer blocker, report the exact sanitized error and attempted correction, and do not claim review success or commit the stage.

Verification strategy
Use a progressive verification cascade.

During implementation:
1. Run the narrowest relevant test or check for the changed behavior.
2. Run the relevant module or bounded-context test set.
3. Run static analysis, linting, or build checks relevant to changed files.

At stage completion:
4. Run the full relevant stage check set.

At final handoff:
5. Run the full project check set when practical and supported by the environment.

A targeted green test is not enough to declare a stage complete when broader relevant checks are available.
Prefer fast feedback during implementation and broader confidence before handoff.

Regression tests for bug fixes
For every bug fix, add a regression test when technically practical.

The regression test must:
- fail against the previous incorrect behavior,
- pass after the fix,
- verify observable behavior rather than private implementation details.

If an automated regression test is not practical, document why and provide the strongest available alternative verification.
A bug fix without a regression test or documented alternative verification is not complete.

Failed command handling
Do not repeat the same failed command without changing code, configuration, environment, or the diagnostic hypothesis.

After an identical repeated failure:
- stop blind retries,
- inspect the root cause,
- collect evidence,
- run a narrower diagnostic command,
- then fix the cause or STOP with a concrete report.

Do not use repeated command execution as a substitute for diagnosis.

Backward-compatible database evolution
Prefer expand/contract for database and persistent contract changes:

```text
1. Expand   — add compatible schema or fields without removing old behavior.
2. Migrate  — backfill or transform data in a separate controlled step.
3. Switch   — enable the new code path after compatibility is verified.
4. Contract — remove old schema or behavior in a later reviewed task.
```

Do not combine schema expansion, full backfill, production switch, and removal of the old schema in one stage unless explicitly required and reviewed.

For every migration-related stage, review:
- generated SQL,
- indexes and constraints,
- nullable/default behavior,
- lock and table-scan risk,
- compatibility between old and new application versions,
- data volume and backfill strategy,
- rollback or forward-fix strategy.

Checkpoint and resume
For every large task, maintain a resumable checkpoint.

If a task id exists, save it to:
```text
docs/tasks/<id>/checkpoint.md
```

The checkpoint must include:
```md
## Current checkpoint

**Phase:** <phase or stage>
**Status:** <planned | implementing | checking | reviewing | fixing | stopped | done>

### Completed
- ...

### Current diff / affected files
- ...

### Checks and baseline
- ...

### Review status
- iteration: ...
- unresolved findings: ...

### Exact next action
- ...

### Files to inspect first on resume
- ...
```

Update the checkpoint:
- after completing a stage,
- before every mandatory STOP,
- after an environment failure that prevents continuation,
- before ending an unfinished work session when possible.

When resuming:
1. Read the checkpoint first.
2. Verify it against the current branch, `git status`, and actual files.
3. Do not trust stale checkpoint content over repository state.
4. Continue from the exact next action instead of repeating completed analysis.

Stage completion
A stage is complete only when:
- its Definition of Done is satisfied,
- implementation is finished,
- baseline and final results were compared,
- the relevant verification cascade was completed,
- internal automatic review was performed,
- external Claude Code review was performed and returned `REVIEW_GREEN`, or was explicitly `N/A — documentation only`,
- review fixes were applied,
- repeat checks were run,
- no unresolved BLOCKER or IMPORTANT findings remain,
- acceptance criteria for the stage are met,
- the Stage Report is prepared,
- and the checkpoint is updated.

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

#### Definition of Done
- [x] ...

#### Baseline
- `command` — result / pre-existing failure

#### Checks
- targeted: `command` — result
- module: `command` — result
- full relevant stage: `command` — result

#### Internal automatic review
- Iterations: <number>
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: ...
- FOLLOW-UP: ... or none

#### External Claude Code review
- Iterations: <number>
- Result: REVIEW_GREEN
- Confirmed findings fixed: ... or none
- Rejected findings with reason: ... or none

#### Review fixes applied
- ...

#### Risks / reviewer focus
- ...

#### Checkpoint
- `docs/tasks/<id>/checkpoint.md` updated, or N/A
- exact next action: ...

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
- perform the final internal automatic review,
- run the final external read-only Claude Code review of the complete diff,
- fix in-scope findings,
- verify all task constraints and forbidden actions,
- prepare final handoff,
- repeat checks and both reviews until the external review returns `REVIEW_GREEN`,
- commit only the current task changes after both final reviews are green,
- push the current task branch without force,
- create or update the Draft PR,
- report the Draft PR and final results to the owner.

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
- internal automatic review summary and review-fix iterations,
- external Claude Code review result, iterations, confirmed fixes, and reasoned rejections,
- risks,
- known limitations,
- follow-up tasks intentionally left out of scope,
- what the owner should review,
- branch name and Draft PR URL when available,
- the exact expected owner response when approval is required.

Do not STOP before commit, non-force push, Draft PR creation, or handoff reporting. Always STOP before merge, release, deployment, production mutation, or another HIGH-EXTERNAL action. Do not merge autonomously.

Forbidden in autonomous mode
Never do these autonomously:
```text
expand scope without owner approval
invent missing business or financial rules
skip Phase 0 for a large task
skip internal automatic review because the change looks obvious
skip external Claude Code review for executable changes
allow the external reviewer to edit files or change Git state
claim REVIEW_GREEN when the external review command failed or did not return the marker
skip baseline or Definition of Done for a large stage without documenting why
declare a stage complete with unresolved BLOCKER or IMPORTANT findings
repeat the same failed command without a new diagnostic hypothesis or change
combine expand, backfill, switch, and contract in one database stage without explicit review
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
finish with review, commit, push, or Draft PR creation still listed as an executable next step
delegate starting or conducting the required reviews to the owner
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
After the final internal automatic review is green and the external Claude Code review returns `REVIEW_GREEN`, commit only the current task files, push the current task branch without force, and create or update a Draft PR without asking for another confirmation.
If unrelated uncommitted changes exist, stage only the files or hunks owned by the current task. STOP only when changes overlap and cannot be separated safely.
Never commit secrets, generated local artifacts, unrelated owner changes, or files outside the task scope.
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
## SSH и удалённые операции
- Не использовать SSH-agent, проброшенный из JetBrains Remote Development.
- Для Git использовать настроенный в репозитории `core.sshCommand`.
- SSH-команды выполнять только в неинтерактивном режиме (`BatchMode=yes`).
- Не запрашивать повторно доступ к SSH-ключу, если проверка `git ls-remote origin HEAD` проходит успешно.
- Недоступность GitHub или production не должна останавливать текущий Stage:
  необходимо завершить локальную разработку, self-review и доступные тесты.
- Если удалённая операция недоступна, зафиксировать блокировку и продолжить всю доступную работу.
- Запрашивать участие пользователя только на `Release/Production Gate`, если без него невозможно выполнить push или deploy.
- Не изменять SSH-ключи, права доступа и SSH-конфигурацию без отдельного задания пользователя.
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

Production logging and maintenance artifacts:
Application, worker, and scheduler logs must go to container stdout/stderr; Docker owns their rotation.
Do not add cron redirections to host log files.
Never write operational artifacts to `/root` or another user's home directory, and never use a relative output path on production.
Use `/var/log/app-service-finance/maintenance/` for retained manual logs, `/var/backups/app-service-finance/` for backups, and `/var/tmp/app-service-finance.*` for temporary audits.
Creating these paths, installing logrotate configuration, moving existing files, or deleting them is a production mutation and requires an explicit owner gate.
Follow `docs/maintenance/production-logging.md`.

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
3. STOP and obtain explicit owner approval for the production permission change. By default, the owner/DevOps applies it; Codex changes the wrapper only when explicitly instructed for that exact operation.
4. Allowlist the exact Symfony command and validate permitted arguments/flags; command-name-only allowlisting is insufficient when the same command has read-only and mutating modes.
5. Do not broaden sudoers and do not add direct Docker access.
6. Verify the wrapper with `bash -n /usr/local/bin/codex-console`.
7. Verify as the restricted user with `sudo -u codex-prod sudo /usr/local/bin/codex-console <command> --help` or another safe read-only invocation.
8. Document durable policy changes in this file; do not document temporary one-off permissions unless they should remain available.

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
Internal automatic review result.
External Claude Code review result and iterations.
Commit, branch, and Draft PR URL when created.
Risks or follow-up actions.
Anything not completed.
Be concise. Do not over-explain.
