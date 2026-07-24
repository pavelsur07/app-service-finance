# UI mode switch — final handoff

## Result

- Stage 1 isolated Tabler legacy and UI Kit app assets/layouts.
- Stage 2 added the server-side `ui_mode=legacy|app` preference and safe header
  controls.
- Stage 3 added the app dashboard pilot while keeping non-migrated routes on
  legacy templates.
- Stage 4 expanded the switch from `ROLE_ADMIN` to authenticated `ROLE_USER`.

## Contracts

- Layout names: `legacy` and `app`.
- Cookie: `ui_mode`, values `legacy|app`.
- Switch endpoint: `POST /settings/ui-mode`.
- Temporary compatibility: `ui_theme=vf` selects app mode for authenticated
  users during one release.

No database migration, destructive operation, public API change, dependency,
financial-calculation change, or production action is included.

## Verification

- Final targeted UI mode suite: 23 tests / 87 assertions.
- Targeted PHP CS Fixer, Twig lint, PHP syntax, and `git diff --check`: passed.
- Internal automatic review: green.
- External Claude Code review: three Stage 4 passes, final `REVIEW_GREEN`.
- GitHub CI status is checked after the Stage 4 push.

## Known limitations and follow-ups

- Remove the old `ui_theme` compatibility read after the one-release window.
- The legacy header remains desktop-only; add a mobile switch only if the
  product requires switching from legacy on small screens.
- Non-migrated routes intentionally continue to use the legacy shell.

## Release Gate

- Branch: `codex/ui-mode-switch`
- Draft PR: https://github.com/pavelsur07/app-service-finance/pull/2235
- Merge, release, deploy, migrations, and production validation are not
  authorized.

Expected owner response:

`Перевести PR #2235 в Ready. Не merge и не deploy.`
