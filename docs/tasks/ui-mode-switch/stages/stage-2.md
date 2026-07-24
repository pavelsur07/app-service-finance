# Stage 2 Report — safe UI mode preference

Base commit: `03669042590c1795fd00e99247bc25f8ec5dd064`

## Result

- Replaced the `ui_theme=tabler|vf` contract with stable
  `ui_mode=legacy|app` naming.
- Added a concrete `UiModeResolver` shared by controllers and Twig.
- Enforced the `ROLE_ADMIN` pilot both when writing and reading the cookie, so
  a forged cookie cannot enable app mode for other users.
- Added one-release read compatibility for the old `ui_theme=vf` cookie.
- Replaced the JavaScript/fetch toggle with keyboard-accessible POST forms in
  the legacy header.
- Added CSRF validation, strict input validation, 303 redirects, and
  same-origin return handling.
- Added a one-year HttpOnly, SameSite=Lax cookie which is Secure on HTTPS.
- Updated the canonical UI mode naming and isolation rules in
  `ui-kit/decisions.md`.

The shared switch partial and app-bundle styles include an app variant in
preparation for Stage 3. It is not rendered or visually accepted until the app
header exists.

## Checks

- Unit: `UiModeResolverTest` — passed, 10 tests / 19 assertions.
- Functional: `UiModeControllerTest` — passed, 7 tests / 25 assertions.
- Targeted PHP CS Fixer for all five new PHP/test files — passed.
- PHP syntax checks for all new PHP/test files — passed.
- Twig lint for the legacy layout and switch partial — passed.
- `npm run lint` — passed.
- Vite production build to `/tmp/app-finance-ui-stage2` — passed.
- Router inspection confirms POST-only `/settings/ui-mode`.
- `git diff --check` — passed.
- `make site-cs-check` — same pre-existing repository failure: 585 of 2137
  PHP files need formatting; all Stage 2 PHP files pass the targeted check.

The Vite build retains the pre-existing missing
`@symfony/ux-turbo/package.json` warning but succeeds.

## Reviews

- Internal review: green after adding central authorization for forged-cookie
  protection.
- External review cycle:
  - first pass: `REVIEW_GREEN` with safe MINOR hardening/test/doc findings;
  - all applicable MINOR findings fixed;
  - final pass: `REVIEW_GREEN`.
- Confirmed BLOCKER findings: none.
- Confirmed IMPORTANT findings: none.

## Follow-up

- Stage 3 renders and visually verifies the app variant of the switch.
- Remove the old `ui_theme` compatibility read after one release.
- A mobile switch placement may be added with the responsive app shell; the
  existing legacy header remains desktop-only by design.

## Exclusions

- Conditional dashboard rendering and customer app navigation belong to
  Stage 3.
- Merge, release, deployment, and production changes remain outside the
  authorized scope.
