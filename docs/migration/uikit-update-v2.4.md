# UI Kit Update v2.3 → v2.4

**Date:** 2026-07-09
**Branch:** chore/uikit-update-v2.4
**Mode:** diff
**Source:** site/ui-kit/_incoming/ui-kit-v2.4.html (diff base: ui-kit-v2.3.html)

## Classification

| # | Hunk | Class | Description | Target file |
|---|---|---|---|---|
| 1 | L3-19 | 🟢 storybook-edit | Version bump в `<title>` + CSS-header comment | storybook.html |
| 2 | L130 `:root` | 🟢 token-add | `--s-8: 40px` | tokens/spacing.css (уже присутствовал) |
| 3 | L165 `:root` | 🟢 token-add | `--app-sidebar-width: 264px` | tokens/layout.css |
| 4 | L2132 CSS block | 🟡 pattern-add | APP-SHELL (`.app`, `.app-body`, `.app-sidebar`, `.app-sidebar .sb`, `.app-workzone`) | patterns/app-shell.css + all.css |
| 5 | L2376 | 🟢 storybook-edit | `.nav-version` v2.4 | storybook.html |
| 6 | L2446 | 🟢 storybook-edit | nav-ссылка `#app-shell` (05 Patterns) | storybook.html |
| 7 | L2461 | 🟢 storybook-edit | `.page-meta` v2.4 | storybook.html |
| 8 | L5334 | 🟢 storybook-edit + pattern .html | demo-секция `#app-shell` | storybook.html + patterns/app-shell.html |
| 9 | L5766 | ⚪ export-noise | Cloudflare `__cf_email__` re-hash (артефакт экспорта дизайнера) | не переносится |
| 10 | L5967 | 🟢 storybook-edit | Changelog v2.4 | storybook.html |

Total: 10 hunks, ни одного 🔴 breaking.

## Version bump

v2.3 → v2.4 (**minor**)

Reason: 🟡 pattern-add (APP-SHELL) — наибольший вес. Согласовано с версией в `<title>` / `.nav-version` / `.page-meta` монолита.

## New patterns

| Pattern | Files | React wrapper status |
|---|---|---|
| AppShell | patterns/app-shell.{html,css} + import в patterns/all.css | ⚠️ TODO — отдельная задача |

APP-SHELL — это внешний каркас страницы, который **компонует** существующие `.app-header` (component) и `.sb` (pattern), не изменяя их. Ровно тот каркас, что был временно заведён как `assets/styles/pages/admin-shell.css` в задаче AdminShell; теперь формализован в UI Kit как pattern.

## New tokens

| Token | File | Value |
|---|---|---|
| --app-sidebar-width | tokens/layout.css | 264px |

Примечание: `--s-8: 40px` (hunk #2) уже присутствовал в `tokens/spacing.css` — split-структура была впереди v2.3-монолита. Изменений не потребовалось.

## Sections expected to differ visually

- **Patterns → App Shell**: новая секция `#app-shell` (демо каркаса) — её не было в v2.3.
- **Nav (05 Patterns)**: новая ссылка «App Shell · new».
- **Changelog**: новая запись v2.4.
- Все остальные секции — идентичны v2.3 (правок компонентов/токенов, влияющих на рендер, не было; `--app-sidebar-width` — аддитивный токен, `.app-header`/`.sb` не тронуты).

## Verification

- [ ] Скриншоты — ручная проверка Владельцем при review PR
- [x] check-ui-kit-classes: 472 → 476 определённых классов (+4: `app`, `app-body`, `app-sidebar`, `app-workzone`); новых нарушений нет
- [x] check-uikit-react-mapping: +1 ref-broken-react-ref (AppShell) — ожидаемо, backlog
- [x] npm run build: clean (exit 0), `app-*.css` 67.70 → 68.13 kB, `.app-workzone` присутствует в бандле

## Follow-ups

1. React-обёртка `assets/react/ui-kit/AppShell/AppShell.tsx` (по образцу `uikit-button-wrapper.md`).
2. `templates/admin/base.html.twig` уже использует классы `.app*` из page CSS (`admin-shell.css`) — после мержа v2.4 можно снять локальный `admin-shell.css` и опереться на UI Kit pattern (отдельная задача-чистка).
