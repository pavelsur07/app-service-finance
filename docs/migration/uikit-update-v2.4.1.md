# UI Kit Update v2.4 → v2.4.1

**Date:** 2026-07-09
**Branch:** chore/uikit-update-v2.4.1
**Mode:** diff
**Source:** site/ui-kit/_incoming/ui-kit-v2.4.1.html (diff base: ui-kit-v2.4.html)

## Classification

| # | Hunk | Class | Description | Target file |
|---|---|---|---|---|
| 1 | L3 | 🟢 storybook-edit | Version bump в `<title>` | storybook.html |
| 2 | L1638 | 🟢 component-edit | `.sb-foot .me-name` → truncate (`white-space: nowrap; overflow: hidden; text-overflow: ellipsis`) | patterns/sidebar.css |
| 3 | L2376 | 🟢 storybook-edit | `.nav-version` v2.4.1 | storybook.html |
| 4 | L2461 | 🟢 storybook-edit | `.page-meta` v2.4.1 | storybook.html |
| 5 | L5393 | 🟢 demo-edit | Кнопка «Выйти» (`.settings` + logout-icon) в `.sb-foot` демо AppShell | patterns/app-shell.html + storybook.html |
| 6 | L5766 | ⚪ export-noise | Cloudflare `__cf_email__` re-hash | не переносится |
| 7 | L5967 | 🟢 storybook-edit | Changelog v2.4.1 | storybook.html |

Total: 7 hunks, ни одного 🔴 breaking. Новых компонентов/токенов нет.

## Version bump

v2.4 → v2.4.1 (**patch**)

Reason: 🟢 component-edit (`.me-name` ellipsis) — наибольший вес. Согласовано с версией в `<title>` / `.nav-version` / `.page-meta` монолита.

## New components / tokens

Нет.

## Sections expected to differ visually

- **Patterns → App Shell**: в подвале сайдбара демо появилась кнопка «Выйти» (её не было в v2.4).
- **Patterns → Sidebar** и **App Shell**: длинное имя пользователя в `.sb-foot` теперь обрезается многоточием (CSS-правка в `sidebar.css`, применяется к обоим демо).
- **Changelog**: новая запись v2.4.1.
- Остальные секции идентичны v2.4.

## Verification

- [ ] Скриншоты — ручная проверка Владельцем при review PR
- [x] check-ui-kit-classes: 476 → 476 (новых классов нет, patch), 0 новых нарушений
- [x] check-uikit-react-mapping: без изменений (45 missing refs — pre-existing backlog)
- [x] npm run build: clean (exit 0), `app-*.css` 68.13 → 68.19 kB; `.me-name{…text-overflow:ellipsis}` присутствует в бандле

## Follow-ups

1. React-обёртка AppShell (перенесено из v2.4 backlog) — если/когда обёртка появится, добавить logout-кнопку в её `sb-foot`.
