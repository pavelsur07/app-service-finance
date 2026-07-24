# Stage 3 Report — dual-rendered dashboard pilot

Base commit: `b05340a6d2fd1c4c3c20afce0b0e6bbc6b1f4d1f`

## Result

- `/` keeps the existing Tabler dashboard by default.
- `ROLE_ADMIN` with `ui_mode=app` receives a dedicated UI Kit dashboard.
- Both templates consume the same `HomeController` KPI array and use the same
  inflow, outflow, balance, and net-flow formulas.
- Added a customer app shell composed from the existing UI Kit app header,
  app shell, sidebar, page scaffold, KPI, money, button, and avatar patterns.
- Added the working app-header interface switch and a one-item pilot sidebar.
- Added responsive behavior: the desktop sidebar is hidden on narrow screens,
  workzone padding shrinks, and logout remains available in the header.
- Passed the already authorized active company from the controller to the app
  header, including member-access cases.
- Confirmed non-migrated customer routes continue rendering legacy templates.
- Updated the screen matrix and canonical UI mode architecture decision.

No new route, service, dependency, financial calculation, or database change
was introduced.

## Checks

- `UiModeResolverTest` — passed, 10 tests / 19 assertions.
- Combined UI mode functional set — passed, 11 tests / 50 assertions.
- Final `HomeUiModeTest` — passed, 5 tests / 36 assertions.
- Targeted PHP CS Fixer for all task PHP/test files — passed.
- Twig lint for all app/legacy layouts, shell partials, dashboard, and switch
  partial — passed.
- `npm run lint` — passed.
- Vite production build to `/tmp/app-finance-ui-stage3-final` — passed.
- `git diff --check` — passed.
- `npm run check:ui-kit` — pre-existing global failure; the checker now reads
  `ui-kit/base.css` and app-local CSS definitions directly. Remaining debt:
  8946 usages. Stage 3 app templates add no unresolved class.
- `make site-cs-check` — same pre-existing failure: 585 of 2138 PHP files;
  all task PHP files pass the targeted check.

The Vite build retains the pre-existing missing
`@symfony/ux-turbo/package.json` warning but succeeds.

## Reviews

- Internal review: green after preserving mobile logout and centralizing the
  active-company value.
- External review cycle:
  - initial 40-turn pass exhausted its reviewer budget and was retried with the
    prescribed 80-turn narrow scope;
  - review returned `REVIEW_GREEN` with safe MINOR findings;
  - all applicable findings were fixed and regression-tested;
  - final accessibility pass returned `REVIEW_GREEN`.
- Confirmed BLOCKER findings: none.
- Confirmed IMPORTANT findings: none.
- Rejected optional finding: missing `additionalCssFiles` should fail loudly
  because both files are required checker inputs.

## Release Gate

The Draft PR contains all three stages and remains draft. Owner decisions:

1. keep the pilot restricted to `ROLE_ADMIN` or schedule broader
   `ROLE_USER` access;
2. keep the PR as Draft or authorize marking it Ready for review.

Neither choice authorizes merge, release, deployment, production cache
changes, or any other Production Gate action.

## Follow-up

- Remove the old `ui_theme` compatibility read after one release.
- Add mobile navigation before a second app route is introduced.
- Add a UI Kit flash-message presentation when an app screen starts producing
  user-visible flashes.
