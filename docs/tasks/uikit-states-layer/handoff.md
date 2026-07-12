# Handoff: Two-layer CSS ownership — engineering behavior layer (states.css)

**Branch:** `chore/uikit-states-layer` · **Date:** 2026-07-12 · **Риск задачи:** 🔴 HIGH (смена DS-контракта)
**План:** `docs/tasks/uikit-states-layer/plan.md` (одобрен Владельцем, с патчем: states.css из app.css, не из all.css; lint-проверка до Stage 2)

## Summary по этапам

| Stage | Что | Риск | Коммит |
|---|---|---|---|
| 1 | Правило: §2 CLAUDE.frontend.md (4-й слой + исключение) + ADR #16 + Don't в decisions.md | 🔴 | `ad9f1448` (ревью Владельца пройдено) |
| 2 | `components/states.css` (autofill-фикс `.input`) + import в app.css + link в storybook | 🟡 | `a6b83f8f` |
| 3 | uikit-update.md: head-шаблон Phase 4, Phase 6.1, self-review чекбокс, never-do | 🟡 | `ff037ac0` |
| 4 | Patch-bump v2.4.1 → v2.4.2 (storybook ×3 + changelog-блок) + CHANGELOG.md + handoff | 🔴 | (этот коммит) |

## Изменённые контракты

- **DS-контракт (one-way door):** источников правды теперь четыре — добавлен behavior layer (`ui-kit/components/states.css`, владелец — код). Правило из states.css — не баг lint и не регрессия uikit-update.
- Каскад: states.css подключается **последним** из `assets/styles/app.css` (после patterns/all.css), мимо дизайнерского all.css. В storybook — отдельный `<link>`.
- Публичный API приложения, БД, PHP — не затронуты. Миграций нет.

## Non-goals соблюдены

- Fix-матрица F1–F4 НЕ применялась — только каркас + одно доказательное правило (autofill).
- React-обёртки, Twig, `_legacy/`, `tokens/*`, `_incoming/`, `components/all.css` — не тронуты (all.css: `git diff master` пуст).

## Verification (всё прогнано на финальном состоянии ветки)

- ✅ `check-ui-kit-classes.mjs` — вывод **байт-идентичен master** (diff против baseline-прогона на stash). Exit 1 и там и там — legacy-нарушения (btn-link, text-muted, mb-0), существовали до задачи, states.css не добавил ни одного.
- ✅ `check-uikit-react-mapping.mjs` — байт-идентичен master (tree-picker ref-broken — pre-existing). Css без html-пары маппер не видит — конфиги линтеров не менялись, states.css вайтлистить не нужно.
- ✅ `npm run build` — green; в `app-*.css` autofill-правила после всех ui-kit-правил (байт 66529/68878), дальше только локальные правила app.css.
- ✅ `grep -nE '#hex|rgb' states.css` — пусто (только `--input-*` токены из `tokens/layout.css:63-70`).
- ⬜ Ручной smoke Владельцем: autofill на форме логина — фон `.input` токенный, на фокусе кольцо; storybook (`v2.4.2`) — превью Input.

## Риски

- `:autofill` без префикса (Firefox) намеренно не добавлен — FF не поддерживает `-webkit-text-fill-color`-хак, его autofill не ломает токены. Добавить при реальной жалобе.
- states.css физически лежит в `components/` — от случайного сноса при full-replace uikit-update защищают never-do строка + self-review чекбокс (осознанный минимум по патчу Владельца).

## Follow-ups (за scope)

1. **F1–F4**: переписать так, чтобы поведение шло в states.css, а визуальные состояния (hover/focus/active цвета) — в монолит дизайнеру. Отдельная задача.
2. `ui-kit/CHANGELOG.md`: хвостовая строка «текущая версия 1.2.0» — устаревшая, оставлена (удаление = отдельное решение Владельца).
3. `ui-kit/README.md` до сих пор говорит «v1.1» — та же история, не трогал.
