### Stage 4: Authenticated-user rollout — DONE

**Risk:** HIGH-LOCAL
**Owner gate:** yes
**Release candidate:** yes
**Independently deployable:** yes
**Next action:** STOP, owner action required

#### Stage scope

- Stage base commit: `ba47d3753bd81cf853042b892c85d9242e1c6292`
- Work items completed: `4.1`, `4.2`, `4.3`

#### What was done

- Expanded the UI mode read, write, and Twig presentation gates from
  `ROLE_ADMIN` to `ROLE_USER`.
- Preserved admin behavior through the application's existing guarantee that
  every authenticated user receives `ROLE_USER`.
- Added functional coverage for explicit `ROLE_USER`, administrators, company
  owners, anonymous requests, and the legacy `ui_theme=vf` compatibility path.
- Kept CSRF, redirect, cookie, legacy fallback, and asset-isolation behavior
  unchanged.

#### Files changed

- `site/src/Shared/Service/UiModeResolver.php`
- `site/src/Shared/Controller/UiModeController.php`
- `site/templates/partials/_ui_mode_switch.html.twig`
- `site/tests/Unit/Shared/Service/UiModeResolverTest.php`
- `site/tests/Functional/Shared/Controller/UiModeControllerTest.php`
- `site/tests/Functional/Finance/HomeUiModeTest.php`
- `site/ui-kit/decisions.md`
- `docs/tasks/ui-mode-switch/plan.md`

#### Definition of Done

- [x] Authenticated users can read and write a supported UI mode.
- [x] Ordinary users can render and use the header switch.
- [x] A forged anonymous cookie cannot activate app mode.
- [x] Anonymous POST requests cannot set the preference cookie.
- [x] Admin compatibility and all existing security controls remain intact.
- [x] No asset, financial, database, or production behavior changed.

#### Baseline

- Targeted UI mode PHPUnit set — passed, 22 tests / 80 assertions.

#### Checks

- Targeted UI mode PHPUnit set — passed, 23 tests / 87 assertions.
- Targeted PHP CS Fixer — passed, 0 of 5 files require changes.
- Twig lint for the switch partial — passed.
- PHP syntax for changed PHP/test files — passed.
- `git diff --check` — passed.

#### Internal automatic review

- Iterations: 2
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: explicit `ROLE_USER` coverage, realistic company-owner coverage,
  anonymous no-cookie assertion, and compatibility documentation.
- FOLLOW-UP: remove the old `ui_theme` compatibility read after its one-release
  window; add a legacy mobile switch placement if required.

#### External Claude Code review

- Iterations: 3
- Result: `REVIEW_GREEN`
- Confirmed findings fixed: anonymous no-cookie assertion, realistic role
  coverage, explicit legacy-cookie coverage for `ROLE_USER`, and isolated
  anonymous test setup.
- Rejected finding: centralizing three role literals would add a Twig-facing
  API and indirection without reducing meaningful risk.

#### Risks / reviewer focus

- The old `ui_theme=vf` cookie selects app mode for authenticated users during
  the documented compatibility window.
- The existing legacy header remains desktop-only; this Stage does not change
  responsive navigation.

#### Checkpoint

- `docs/tasks/ui-mode-switch/checkpoint.md` updated.
- Exact next action: owner decides whether Draft PR #2235 becomes Ready.

#### Expected owner response

Recommended response:
`Перевести PR #2235 в Ready. Не merge и не deploy.`

Alternative response:
`Оставить PR #2235 в Draft.`
