# UI Kit Update v1.4 → v2.1

**Date:** 2026-07-03
**Branch:** chore/uikit-update-v2.1
**Mode:** full-replace
**Source:** site/ui-kit/_incoming/ui-kit.html (v2.1, designer monolith)

## Mode rationale

`_incoming/` contains a single unversioned `ui-kit.html` — no previous monolith
to diff against → **full-replace**. The split structure (v1.4) had drifted far
behind the monolith (which carries its own v1.5→v2.1 changelog), so the entire
`tokens/ components/ patterns/` tree was regenerated from the monolith rather
than patched hunk-by-hunk. Extraction was done with a deterministic script and
verified lossless by reassembly (token `:root` inner + component-CSS region both
round-trip to the source, modulo whitespace/ordering).

## Version bump

v1.4 → **v2.1** (major). The designer's monolith is authoritative; version is
consistent across `<title>`, `.nav-version`, `.page-meta`, and the changelog
entry inside the monolith. Reason: many new components + reorganization.

## What was regenerated

| Layer | Result |
|---|---|
| `tokens/*.css` | `:root` re-split across 7 files by category (colors, typography, spacing, radius, shadows, layout, semantic). `tokens/index.css` regenerated. |
| `components/*.{html,css}` | 41 demos, 35 css files. |
| `patterns/*.{html,css}` | 2 demos, 2 css files. |
| `storybook.html` | Rebuilt as shell: `<head>` links `tokens/index.css` + `components/all.css` + `patterns/all.css`; `<style>` reduced to storybook-shell only (no `:root`, no component CSS); `<body>` taken wholesale from the monolith. |
| `all.css` (both dirs) | Regenerated, alphabetical `@import` order. |

## New components (need React wrappers — separate task)

| Component | Files | React wrapper |
|---|---|---|
| Textarea | components/textarea.{html,css} | ⚠️ TODO |
| Select | components/select.{html,css} | ⚠️ TODO |
| SearchInput | components/search-input.{html,css} | ⚠️ TODO |
| PasswordInput | components/password-input.{html,css} | ⚠️ TODO |
| Checkbox | components/checkbox.{html,css} | ⚠️ TODO |
| Radio | components/radio.{html,css} | ⚠️ TODO |
| MarketplaceBadge | components/marketplace.{html,css} | ⚠️ TODO |
| CoverageMatrix | components/coverage.{html,css} | ⚠️ TODO |
| Card | components/card.{html,css} | ⚠️ TODO |
| TreePicker | components/tree-picker.{html,css} | ⚠️ TODO (was folded into `picker` in v1.4) |
| AppHeader | components/app-header.{html,css} | ⚠️ TODO |
| Breadcrumbs | components/crumbs.{html,css} | ⚠️ TODO |
| HeaderButton | components/h-btn.{html,css} | ⚠️ TODO |
| AbcPill | components/abc-pill.{html,css} | ⚠️ TODO |
| HeavyTable | patterns/heavy-table.{html,css} | ⚠️ TODO |

`EntityPicker` also became its own file (`components/entity-picker.*`), split
from the v1.4 shared `picker`.

## Structural notes / decisions

- **Directory by body section.** The monolith moved most demos under
  `#components` (sidebar, modal, drawer, pickers, tags, report, acc-card…), so
  those files now live in `components/` rather than `patterns/`. Only demos under
  the monolith's `#patterns` section (`heavy-table`, `auth-msg`, +
  `row-form-field.css`) stay in `patterns/`. Both dirs are imported by
  `assets/styles/app.css` and scanned by both linters, so the move is functional
  no-op for the build.
- **Marker-faithful CSS split.** CSS was split on the monolith's
  `/* ===== NAME ===== */` markers (37 blocks). Where the designer did *not* give
  a block its own marker, its rules stay under the adjacent marker — e.g.
  `.empty*` and `.drawer*` rules live in `tags.css` because the monolith groups
  them there. This mirrors the designer's structure so the next diff-mode update
  lines up cleanly. `drawer.html` / `empty.html` therefore have no same-named
  `.css` (and no `@docs` line); their classes are defined in `tags.css`.
- **Demo-only references** (no `@react`, expected `ref-no-react-mapping`):
  `modals-live`, `t-sort`, `t-totals`, `t-pg` (table usage rows), `auth-msg`
  (Alert usage). These are compositional examples, not canonical components.
- **Not split out:** four un-`id`'d `<div class="cb">` demos in the monolith's
  `#patterns` section (Row dropdown, Form field, Section header, Сумма+meta) have
  no `id`, so they were not extracted as standalone reference files. They remain
  fully present in `storybook.html`. Follow-up if standalone refs are wanted.

## Verification

- [ ] Скриншоты — ручная проверка Владельцем при review PR (`storybook.html` vs `_incoming/ui-kit.html`)
- [x] Lossless reassembly: token `:root` inner + component-CSS region round-trip to source
- [x] `check-ui-kit-classes`: **9275 → 8986** violations. All are pre-existing legacy Bootstrap/Tabler classes in `templates/` (`card`, `col-12`, `mb-4`, `text-end`…), unrelated to UI Kit. Count **decreased** because the new components define more classes; **zero new** violations introduced.
- [x] `check-uikit-react-mapping`: **22 → 42** violations (refs 23 → 43). Growth = 20 new component references awaiting React wrappers (backlog, not a blocker). `Button` link intact.
- [x] `npm run build`: CSS transforms clean (102 modules), UI Kit styles bundled into `app-*.css` (~67 KB, grown). Verified via `vite build --outDir <scratch>`; the default `public/build/` target fails only on `emptyDir` of a stale **root-owned** `public/build/.vite` dir — pre-existing environment permission issue, unrelated to this change.
- [x] All 43 generated HTML demos have balanced `<div>` tags (slicing integrity).
- [x] Files in `_incoming/` untouched. No changes outside `site/ui-kit/` and `docs/migration/`.

## Follow-ups

1. React wrappers for the 15 new components above (separate task, per `uikit-button-wrapper.md`).
2. Migrate remaining `templates/` off legacy Bootstrap classes (8986 pre-existing class-checker violations) — out of scope here.
3. Optional: extract the four un-`id`'d `#patterns` demos as standalone reference files if wanted.
