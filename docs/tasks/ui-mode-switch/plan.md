# UI mode switch — implementation plan

Owner brief: support the existing Tabler interface and the primary UI Kit
interface side by side, switchable from the header, with no CSS leakage and no
unnecessary infrastructure.

## Scope and decisions

- Stable internal names: `legacy` and `app`.
- One Symfony application, authentication model, route set, and business layer.
- Browser preference is stored in an `ui_mode` cookie.
- Existing templates stay in place; no bulk move of the 112 legacy templates.
- Existing `base.html.twig` remains a compatibility entry for legacy pages.
- A route/controller opts into the app UI only when an app template exists.
- The pilot initially shipped for `ROLE_ADMIN`; Stage 4 expands it to
  authenticated `ROLE_USER` after the owner Release Gate decision.
- Admin and authentication layouts remain app-only and are not switchable.
- No database migration, new dependency, iframe, microfrontend, or production action.

## Baseline

- Stage base commit: `10fddca3b6374558a129402bfb26e762d3273646`
- Commands:
  - `npm run build`
  - `npm run lint`
  - targeted existing PHP checks selected after test inventory
- Pre-existing failures are recorded before executable changes.

## Stage 1: Isolated legacy and app presentation shells

Risk: HIGH-LOCAL
owner_gate: no
release_candidate: no
independently_deployable: yes
stage_base_commit: `10fddca3b6374558a129402bfb26e762d3273646`

Definition of Done:

- Legacy pages load Tabler, Inter, legacy CSS, and legacy runtime only.
- App/admin/auth pages load Manrope, UI Kit CSS, and app runtime only.
- The UI Kit global `.btn`, `.card`, and `.alert` rules are absent from legacy responses.
- Tabler CSS/JS is absent from app responses.
- Existing legacy templates continue extending `base.html.twig` unchanged.
- Existing project picker, Stimulus controllers, admin shell, and login behavior remain compatible.
- The obsolete mixed-theme toggle is hidden until Stage 2 replaces it with the
  working interface switch; default legacy rendering remains unchanged.

Work items:

- 1.1 — Extract legacy-only CSS from the mixed `assets/styles/app.css`.
- 1.2 — Create `legacy_app` and clean `app` Vite entrypoints.
- 1.3 — Introduce `_layout/legacy.html.twig` while keeping `base.html.twig` compatible.
- 1.4 — Make admin/auth layouts consume only the app bundle.

Stage checks:

- `npm run lint`
- `npm run build`
- Twig lint for affected layouts
- Static asset-reference assertions
- Focused legacy/app HTML review

Reviewer focus:

- CSS/JS leakage, duplicated assets, Bootstrap modal compatibility, Stimulus startup,
  and backward compatibility for all current `base.html.twig` children.

## Stage 2: Safe server-side UI mode preference and header controls

Risk: MEDIUM
owner_gate: no
release_candidate: no
independently_deployable: yes
stage_base_commit: `03669042590c1795fd00e99247bc25f8ec5dd064`

Definition of Done:

- Stable names are `ui_mode`, `legacy`, and `app`; `theme`, `vf`, and `tabler`
  no longer define the switching contract.
- Invalid values are rejected, CSRF is required, and redirects remain same-origin.
- The cookie is persistent, HttpOnly, SameSite=Lax, and Secure on HTTPS.
- Switching works without JavaScript.
- Header controls are keyboard accessible and expose switch semantics.
- Initial access remains `ROLE_ADMIN`.
- The old cookie can be read during a one-release compatibility window.

Work items:

- 2.1 — Add a concrete `UiModeResolver` shared by controllers and Twig.
- 2.2 — Replace `UiThemeController`/`UiThemeExtension` with UI mode equivalents.
- 2.3 — Replace fetch-driven controls with POST forms in legacy and app headers.
- 2.4 — Add unit and functional regression tests.

Stage checks:

- Targeted unit/functional PHPUnit tests
- `make site-cs-check`
- `npm run lint`
- Twig lint

Reviewer focus:

- CSRF, open redirects, cookie attributes, invalid input, authorization, accessibility,
  and compatibility with the just-merged `ui_theme` cookie.

## Stage 3: Dual-rendered dashboard pilot

Risk: HIGH-LOCAL
owner_gate: yes
release_candidate: yes
independently_deployable: yes
stage_base_commit: `b05340a6d2fd1c4c3c20afce0b0e6bbc6b1f4d1f`

Definition of Done:

- `/` renders the unchanged legacy dashboard by default.
- `ui_mode=app` renders a dedicated UI Kit dashboard under the app shell.
- Both views use the same controller data and authorization.
- The app shell has its own header/sidebar and contains the mode switch in header actions.
- The app sidebar exposes only routes deliberately supported during the pilot.
- Switching in either direction preserves the dashboard URL.
- Non-migrated routes remain legacy and are not conditionally restyled.
- The screens implementation matrix and UI mode architecture decision are updated.

Work items:

- 3.1 — Build the customer app shell from existing UI Kit app-shell patterns.
- 3.2 — Add the UI Kit dashboard template using existing controller data.
- 3.3 — Select the dashboard template through `UiModeResolver`.
- 3.4 — Add response-level isolation and behavior tests.
- 3.5 — Run full relevant checks and both review cycles.

Stage checks:

- Targeted dashboard and UI mode functional tests
- `npm run lint`
- `npm run build`
- `npm run check:ui-kit` with baseline comparison
- `make site-cs-check`
- Full relevant PHPUnit set
- Internal independent review from Stage base
- External read-only Claude Code review to `REVIEW_GREEN`

Reviewer focus:

- Observable parity, tenant/auth behavior, asset isolation, responsive shell,
  accessible switch behavior, safe fallback to legacy, and unnecessary duplication.

## Stage 4: Authenticated-user rollout

Risk: HIGH-LOCAL
owner_gate: yes
release_candidate: yes
independently_deployable: yes
stage_base_commit: `ba47d3753bd81cf853042b892c85d9242e1c6292`

Definition of Done:

- Every authenticated `ROLE_USER` can see and submit the UI mode switch wherever
  the existing header exposes it; the legacy header remains desktop-only.
- A valid `ui_mode=app` cookie selects the app dashboard for `ROLE_USER`.
- Anonymous requests cannot write or activate app mode.
- Existing admin behavior, CSRF validation, redirect protection, cookie
  attributes, and legacy fallback remain unchanged.
- No UI assets, financial behavior, database schema, or production state changes.

Work items:

- 4.1 — Change the centralized read, write, and presentation access checks to
  `ROLE_USER`.
- 4.2 — Update unit and functional regression coverage for ordinary users,
  administrators, and anonymous requests.
- 4.3 — Update the canonical rollout decision and complete both review gates.

Stage checks:

- Targeted UI mode unit and functional tests
- Targeted PHP CS Fixer and Twig lint
- `git diff --check`
- Internal independent review from Stage base
- External read-only Claude Code review to `REVIEW_GREEN`

Reviewer focus:

- Authorization consistency across resolver, endpoint, and Twig; anonymous
  fallback; CSRF and redirect protections; unchanged asset isolation.

## Release and production gates

- Stage 3 Release Gate decision: broaden the switch from `ROLE_ADMIN` to
  `ROLE_USER`; keeping or changing Draft status remains a separate owner decision.
- Stage 4 returns to the Release Gate after the authenticated-user rollout is
  checked, reviewed, committed, pushed, and reflected in the Draft PR.
- Merge, release, deployment, production cache changes, and production validation
  are separate Production Gate actions and are not authorized by this task.
