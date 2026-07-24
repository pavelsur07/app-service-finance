# Stage 1 Report — isolated presentation shells

Base commit: `10fddca3b6374558a129402bfb26e762d3273646`

## Result

- Added separate `legacy_app` and `app` Vite entrypoints.
- Moved Tabler-only runtime and compatibility styles into `legacy_app`.
- Kept UI Kit imports and application bridge styles in `app`.
- Added explicit `_layout/legacy.html.twig` and `_layout/app.html.twig` shells.
- Preserved `base.html.twig` as the compatibility entrypoint for existing
  Tabler templates.
- Kept admin and modern authentication layouts on the UI Kit bundle.
- Hid the obsolete mixed-theme toggle pending its functional replacement in
  Stage 2.

## Compatibility

- Existing Twig block names and nested `content`/`body` behavior are preserved.
- Existing legacy tag-picker and screen-reader live-region styles are provided
  locally without importing UI Kit globals.
- Tabler and UI Kit global component selectors are not loaded into each
  other's presentation shells.

## Checks

- `npm run lint` — passed.
- `npm run build -- --outDir /tmp/app-finance-ui-stage1-review --emptyOutDir`
  — passed; both `app` and `legacy_app` entrypoints generated.
- Docker Twig lint for all six affected layouts — passed.
- Static CSS isolation assertions — passed.
- `git diff --check` — passed.
- `make site-cs-check` — pre-existing repository failure: 585 of 2134 PHP
  files fail the project-wide style check; Stage 1 changes no PHP files.

The Vite build continues to emit the pre-existing missing
`@symfony/ux-turbo/package.json` warning but completes successfully.

## Reviews

- Internal review: green after restoring the tag-picker and `.sr-only`
  compatibility rules, removing an unintended body background, and hiding the
  obsolete no-op toggle.
- External Claude Code review: `REVIEW_GREEN`.
- Confirmed BLOCKER findings: none.
- Confirmed IMPORTANT findings: none after fixes.

## Exclusions

- UI mode persistence and switching behavior are implemented in Stage 2.
- Conditional dashboard rendering and the customer app shell are implemented
  in Stage 3.
- Production build/deployment and any production mutation remain outside the
  authorized scope.
