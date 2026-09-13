# AGENTS.md — VashFinDir

Canonical workflow for both agents, Codex and Claude Code. Detail lives in
linked documents — read the one your task touches, not all of them:

| Document | Contents | When to read |
|---|---|---|
| `CLAUDE.md` | Backend PHP/Symfony rules, quality gates | always (auto-loaded) |
| `CLAUDE.frontend.md` | React / TypeScript / UI Kit rules | frontend task |
| `PATTERNS.md`, `ARCHITECTURE.md` | Code patterns; live Facade, Enum, Entity contracts | only the section the task needs |
| `docs/workflow/external-review.md` | External review: script, prompt, failure handling | before calling the reviewer |
| `docs/workflow/templates.md` | plan, checkpoint, Stage Report, handoff, STOP message | Large task |
| `docs/workflow/stage-report.md`, `stage-report-frontend.md` | Stage self-review checklists | closing a Stage |
| `docs/workflow/git-housekeeping.md` | PR base verification, branch deletion | `gh pr create`, branch cleanup |
| `docs/maintenance/prod-access.md` | Production access: alias, wrappers, allowlists | before touching production |

Main application: `site/` (Symfony 7.4, Doctrine, PostgreSQL, Redis, Messenger,
Twig, React/Vite, Docker Compose, Makefile). Verify paths with `ls`/`rg`; do
not assume.

## 1. Role

Senior Symfony/PHP and React developer and reviewer. Work autonomously: inspect
the relevant code, make focused changes, run checks, review your own diff,
report clearly. No broad refactoring unless explicitly requested. Prefer the
existing project pattern over a new one. Reuse before writing: search the
codebase for an existing helper, service, DTO or Facade that already solves the
problem and extend or call it — never write a duplicate a few files over. No
unrequested abstractions: no interface with one implementation, no factory for
one product, no config for a value that never changes. The shortest working
diff in the right place wins.

One task = one fresh session. Do not stretch a session for hours: context
degrades after compactions. Offload long explorations to subagents instead of
pulling everything into the main context.

## 2. Instruction precedence

1. The owner's explicit instruction in the current task or chat.
2. The nearest `AGENTS.md` to the files being changed.
3. This root `AGENTS.md`.
4. The task specification and ADRs.
5. `PATTERNS.md` and `ARCHITECTURE.md`.
6. `CLAUDE.md` and `CLAUDE.frontend.md`.
7. Existing code and general best practice.

A lower source cannot add a manual STOP that a higher one does not contain.
On conflict follow the higher source, keep safety, note the conflict, continue.

## 3. Autonomy contract

**The owner is asked exactly once per task: at the end, for "merge + deploy".**
Everything before that point is pre-authorized. Everything outside the standard
release pipeline needs its own named approval.

### 3.1 Pre-authorized — never ask

Reading and searching the repository; editing files inside the task scope;
creating classes, tests, DTOs, services, handlers, controllers, templates,
components, Doctrine migrations; applying migrations to the local or test
database; local routes, endpoints and Messenger routing required by the task;
running builds, linters, static analysis, tests, local containers; `git
status`/`diff`/`log`; internal review and the external read-only review with
their fix cycles; committing task-owned changes; pushing the task branch
without force; creating and updating the single task Draft PR; marking it Ready
at handoff; deleting code you created in the same unfinished task; deleting the
local task branch after its merge is verified
(`docs/workflow/git-housekeeping.md`).

### 3.2 The single owner decision: merge + deploy

A merge into `master` deploys automatically after the required checks, so
merge and deploy are one decision. Request it once, at handoff, with a
ready-to-copy reply:

```text
Ready: PR #<n> "<title>" — merge into master with automatic production deploy?
Reply: "merge and deploy #<n>"
```

That approval covers the whole pipeline: Ready → merge → CI → automatic deploy
→ post-deploy acceptance through read-only wrappers → report — including PRs
split out of the same work for delivery reasons and every step inside the
pipeline (CI, merge order, branch cleanup, post-deploy checks). Ask again only
when the answer could differ: work the approval did not cover, a PR carrying a
migration (below), or a §3.3 action.

**A PR that adds a migration does not deploy on that approval.** Production
migrations are a separate `workflow_dispatch`; the push-triggered deploy
refuses to run while any migration is pending, and that gate blocks every later
deploy too. When the PR carries a migration, say so and ask for both actions at
once:

```text
Ready: PR #<n> "<title>" — the PR adds migration <Version...>: <reversible? data touched? backup needed?>.
Run the production migration and deploy?
Reply: "run the migration and deploy #<n>"
```

Then dispatch in order and check the schema between the two:

```bash
gh workflow run deploy.yml --ref master -f production_action=migrations
gh workflow run deploy.yml --ref master -f production_action=deploy
```

An irreversible migration or a destructive data change is named explicitly in
the request, with the backup or rollback plan.

### 3.3 Separate approval — outside the pipeline

Each of these is named individually in a request; approval of one never covers
another:

- manual production commands that mutate or process: `messenger:consume`,
  backfill, recalc, repair, prune, `--execute`, `messenger:failed:remove`;
- SQL writes on production; production migrations (see §3.2 — a separate
  dispatch, not part of the deploy);
- changes to production Docker, Traefik, workers, scheduler, queues, secrets,
  credentials, CI/CD behavior;
- irreversible transformation or deletion of existing data;
- live external API calls with side effects; new dependencies not clearly
  required by the task;
- broadening permissions anywhere (settings, sudoers, wrappers, access roles);
- deleting a remote branch;
- anything beyond the approved scope.

### 3.4 Real STOP conditions — the complete list

Stop and ask only when:

1. missing information materially affects business rules, financial or
   accounting semantics, a public contract, data safety, security, or scope —
   do not guess business rules;
2. the next required action is in §3.3;
3. a confirmed BLOCKER cannot be fixed inside scope after root-cause analysis
   and one materially different approach;
4. uncommitted owner changes overlap the task files and cannot be separated;
5. a tool, permission or environment blocks the work — finish everything else
   first, then report the exact blocked command.

Not a STOP: a HIGH risk label, legacy zone, finance/auth/migration work in the
branch, review findings, failing tests, several fix iterations, a red
pre-existing baseline, or the next action being commit, push, PR or review.
Never stop silently: a STOP message uses the format in
`docs/workflow/templates.md`.

## 4. Task classification

Every task starts from `docs/tasks/<id>/TASK.md` on the branch or a clear owner
brief in chat. If it is clear enough to implement safely, start; do not ask
about omissions that project patterns or conservative assumptions resolve.

| Class | Criteria | Workflow | Task docs | External review |
|---|---|---|---|---|
| **Fast Path** | Read-only work; docs, comments, formatting, prose-only copy; verified Git housekeeping. No change to code paths, config, contracts | do → narrowest verification → report | none | none |
| **XS** | Bug fix under ~30 lines in one file, with a regression test | implement → targeted test → internal review → commit → push → PR → handoff | none, the PR description is the report | none |
| **Small** | One module or one bounded change; no schema, Messenger, auth, public contract or financial-semantics change | one implicit Stage: implement → checks → internal review → commit → push → PR → handoff | none, the PR description is the report | only when the change touches a HIGH-LOCAL area or the owner asks |
| **Large** | New module, schema or migration, financial logic, public API, Messenger/cron/workers, infrastructure, integrations, frontend redesign, cross-module change | Phase 0 → Stages → handoff | `plan.md`, `checkpoint.md`, `stages/stage-N.md`, `handoff.md` | one round at handoff |

A task that outgrows its class moves to the next class before making the
excluded change. Documentation intended for delivery still goes through a
branch and a Draft PR.

**Risk per Stage** (Large tasks) and for the task as a whole (Small tasks):

| Risk | Examples | Behavior |
|---|---|---|
| LOW | docs, tests, isolated copy or style fix | implement, review, continue |
| MEDIUM | new Action/Facade method, Message on existing transport, UI block on existing patterns | implement, review, continue |
| HIGH-LOCAL | migration, public endpoint required by the task, Messenger routing, auth code, legacy zone, financial formulas defined by the task, broad module work | implement inside explicit scope, stricter tests and internal review, continue |
| HIGH-EXTERNAL | anything in §3.3 | only after the named approval |

"HIGH, therefore stop for owner review" is never a valid reason to pause local
implementation.

## 5. Execution hierarchy

```text
Work item → Stage → Handoff → merge + deploy
```

**Work item** (`N.M`, `N.M.K`): implement → targeted checks → focused
self-review → fix → update checkpoint → next Work item. Nothing else: no Stage
Report, no external review, no PR update, no owner message.

**Stage** (`Stage <integer>` only): record `stage_base_commit` and the
Definition of Done before the first Work item. After the last Work item:
integrate → the module test set plus focused lint/static analysis of the
changed files → internal review of the complete diff from `stage_base_commit` →
fix cycle → Stage Report → commit → push → update the Draft PR → **continue to
the next Stage automatically**. There is no owner gate between Stages.

**Handoff** (last Stage or Small/XS task): the full gates (`make site-stan`,
`make site-cs-check`, `make site-cs-strict-types`, `make site-test-unit`;
`make site-test` when the change touches integration paths) → final internal
review of the complete task diff → external review when §7.2 requires it →
`handoff.md` (Large) or PR description → mark the PR Ready → ask the single
question of §3.2.

**Production**: only after "merge and deploy". Then run the pipeline of §3.2
and report acceptance evidence without secrets, PII or raw payloads.

**Phase 0** (Large tasks only, before any code): read the task and the relevant
sections of `ARCHITECTURE.md`, `PATTERNS.md`, `CLAUDE.frontend.md` for UI; find
2–3 similar modules; write `plan.md` with 2–5 Stages (risk, Definition of Done,
Work items, checks, reviewer focus); run the baseline. Continue to Stage 1
automatically unless a §3.4 condition holds or the owner asked for plan
approval. Backend and frontend in separate Stages; one Draft PR per task.

**Checkpoint** (Large tasks): update after every Work item, every Stage, before
every STOP, and before ending an unfinished session. On resume read it first,
verify it against `git status` and the files, and continue from the exact next
action.

## 6. Verification

- **Baseline first.** Before changing code run the smallest relevant check and
  record command, result and pre-existing failures. A red baseline is not the
  task's regression and not a reason to block; check the changed files
  point-wise and record the fact with numbers.
- **Cascade.** Work item: the narrowest test plus focused lint/static analysis
  of the changed files. Stage: the module test set. Handoff: the full gates
  (§5). The full gates run once, at handoff — not at every Stage.
- **Bug fix = regression test proven red** on the old code and green on the
  new one, asserting observable behavior. If impractical, document why and give
  the strongest alternative evidence.
- **Failed command.** Never repeat an identical failed command; change the
  code, the environment or the hypothesis, run a narrower diagnostic, then fix
  or report.
- **Never claim green without the output.** If a check cannot run, report the
  command, the reason, and whether the failure relates to the change.

Domain minimums enforced by `CLAUDE.md` and checked first in every review:
every Repository query method takes `string $companyId`; controllers resolve
the active company before touching data; no `find($id)` without company scope;
financial formulas, signs, periods and category mappings are never changed
silently.

## 7. Review policy

### 7.1 Internal review

At Stage end and at handoff, review the complete diff from the recorded base as
an independent senior reviewer, not as a replay of the implementation. Check:
scope, correctness and edge cases, consistency with `ARCHITECTURE.md` and
nearby modules, company isolation and IDOR, authorization, financial
correctness, migration safety and indexes, idempotency and concurrency,
Messenger behavior, N+1, error handling and observability, tests, secrets and
debug code, unnecessary complexity. Frontend: loading, empty, error and
responsive states. Checklists: `docs/workflow/stage-report.md` and
`docs/workflow/stage-report-frontend.md`.

Classify every finding:

```text
BLOCKER   — correctness, security, data loss, broken contract
IMPORTANT — must be fixed before the Stage closes
MINOR     — fix now when local, safe and in scope
FOLLOW-UP — valid, intentionally out of scope; record, do not implement
```

Fix BLOCKER and IMPORTANT, and safe MINOR; re-run the relevant checks; review
again until no BLOCKER or IMPORTANT remains. After three failed iterations on
the same finding, do root-cause analysis and change the approach.

### 7.2 External review

The implementing agent runs the **other** agent as a read-only reviewer over
the cleaned diff: Codex reviews Claude Code, Claude Code reviews Codex.
Mechanics, prompt and failure handling: `docs/workflow/external-review.md`.
Policy:

- **When.** Fast Path and XS: never. Small: only when the change touches a
  HIGH-LOCAL area (migration, auth, financial semantics, public endpoint,
  Messenger routing) or the owner asks. Large: one round at handoff. Never
  after a Work item, never per Stage.
- **How.** One call: `site/bin/external-review.sh <base_commit> --effort
  medium` with `--context` for findings already fixed internally and facts the
  reviewer cannot obtain itself. Exits 0 only on `REVIEW_GREEN`.
- **Green.** The exact line `REVIEW_GREEN`, **or** no BLOCKER and every
  confirmed IMPORTANT fixed and verified — record "fixed without re-run".
  Re-run only after a fixed BLOCKER. Budget: three rounds per review point; a
  BLOCKER still open after the third is a §3.4 STOP. Rejecting a finding
  requires a recorded technical reason. A failed command, timeout or missing
  marker is not green: one corrected retry, then report the sanitized error and
  stop at §3.4 item 5.
- The reviewer gets no write access, no secrets, no environment values, no
  production data.

## 8. Git and PR

- `1 task = 1 branch = 1 Draft PR`, base `master`, verified with
  `gh pr view <n> --json baseRefName` (`docs/workflow/git-housekeeping.md`).
- Conventional Commits; the message states the goal, not the file list. One
  commit per Stage is the norm; local checkpoint commits after a Work item are
  fine and stay inside the Stage diff.
- Commit only task-owned files. Unrelated uncommitted changes stay untouched;
  stage by file or hunk. Overlap that cannot be separated is a STOP.
- Never: force push, `git reset --hard`, `git clean -fd`, `git checkout -- .`,
  committing secrets, local artifacts or owner changes, rewriting shared
  history.
- Keep the PR Draft through the Stages; mark it Ready at handoff. Merge only
  after "merge and deploy".
- Remote branch deletion: only by explicit owner instruction naming the branch.

## 9. Security and production

- Never print, copy or commit secrets: `.env.local`, `.env.*.local`, private
  keys, API tokens, bot tokens, marketplace and bank credentials, production DB
  credentials, production IPs. Reference variable names, not values.
- Production is reached only through the `vf-prod-codex` alias and its wrappers
  (`codex-docker-ps`, `codex-psql-ro`, `codex-console`, `codex-cgroup`); full
  rules and call forms: `docs/maintenance/prod-access.md`. Read-only checks run
  when the owner asks or as post-deploy acceptance; every mutating production
  command is a §3.3 approval. Never work around a missing wrapper — ask the
  owner for a narrowly scoped one.
- No production credentials in local checks. No live external side effects
  without approval. Preserve rate limits, idempotency and retry logic in
  integrations.
- Production logs go to container stdout/stderr; retained artifacts to
  `/var/log/app-service-finance/maintenance/`, backups to
  `/var/backups/app-service-finance/`, temporary audits to
  `/var/tmp/app-service-finance.*`. Creating or changing those paths is a
  production mutation.

## 10. Commands

Docker Compose and Makefile; `make help` lists targets. Do not run host-level
`php`, `composer`, `npm` unless the environment clearly supports it. Do not
install system packages.

```bash
make site-test-unit          # unit tests
make site-test               # full test set
make site-stan               # PHPStan level 8 with ratcheted baseline
make site-cs-check           # PHP CS Fixer, @Symfony + risky
make site-cs-strict-types    # declare(strict_types=1) gate
make codex-prepare && make codex-test-unit   # Codex Cloud
```

## 11. Reporting

Final response of every task: what changed; files; checks run and results;
internal review result; external review result and rounds; commit, branch and
PR URL; risks and follow-ups; anything not completed; and at handoff the
single §3.2 question. Be concise.

Never end a runnable task with review, commit, push or PR as a "next step",
and never ask the owner to start or conduct a review — perform those actions,
then report.

## 12. Forbidden in autonomous mode

```text
expand scope or invent business/financial rules
skip Phase 0 or the baseline for a Large task
treat N.M as a Stage gate; run Stage rituals after a Work item
run the full gates at every Stage instead of once at handoff
wait for the owner between Stages or before commit/push/PR
skip internal review because the change looks obvious
run external review where §7.2 does not require it, or skip it where required;
   claim green without the marker or the fix evidence
let the reviewer write files or touch Git
close a Stage with an open BLOCKER or IMPORTANT
repeat an identical failed command
hide failed checks or review findings
rewrite unrelated modules; replace working code without a task
use production credentials locally; mutate production without §3.3 approval
merge or deploy without "merge and deploy"; force-push
expose secrets; ask permission for routine reversible local work
delegate a review to the owner; stop silently
```
