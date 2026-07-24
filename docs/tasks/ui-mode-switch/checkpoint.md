## Current checkpoint

**Phase:** Release Gate
**Status:** done
**Stage base commit:** `ba47d3753bd81cf853042b892c85d9242e1c6292`
**Current Work item:** none
**Owner gate:** yes

### Completed

- Stages 1–3 introduced isolated legacy/app shells, the safe UI preference,
  and the dual-rendered dashboard pilot.
- Stage 4 expanded the switch to authenticated `ROLE_USER`.
- Targeted checks and all internal/external review cycles are green.

### Current diff / affected files

- See `docs/tasks/ui-mode-switch/stages/stage-4.md`.
- Unrelated `.gitignore` and local untracked files remain outside task scope.

### Checks and baseline

- Baseline: 22 tests / 80 assertions.
- Final: 23 tests / 87 assertions.
- PHP CS Fixer, Twig lint, PHP syntax, and `git diff --check`: passed.

### Review status

- iteration: final
- unresolved findings: none
- external result: `REVIEW_GREEN`

### Exact next action

- Wait for the owner's Draft-versus-Ready decision for PR #2235.

### Files to inspect first on resume

- `docs/tasks/ui-mode-switch/stages/stage-4.md`
- `site/src/Shared/Service/UiModeResolver.php`
- `site/src/Shared/Controller/UiModeController.php`
- `site/tests/Functional/Shared/Controller/UiModeControllerTest.php`
