# Stage 2: states.css + подключение — DONE

**Риск:** 🟡 MEDIUM
**Следующее действие:** continue autonomously (Stage 3)

## Что сделано

- Создан `site/ui-kit/components/states.css` — behavior layer: шапка «owner=code · не трогается uikit-update», autofill-фикс для `.input` (inset-заливка `var(--input-bg)` + `-webkit-text-fill-color` + caret), на focus — совмещённый box-shadow ring+inset (`var(--input-focus-ring)` + `var(--input-bg)`).
- `site/assets/styles/app.css` — `@import` states.css последним в блоке импортов (после `patterns/all.css`), с комментарием cascade-last. НЕ последней строкой файла — CSS-спека требует @import до остальных правил.
- `site/ui-kit/storybook.html` — `<link>` states.css после `patterns/all.css`, чтобы фикс был виден в превью.
- `site/ui-kit/components/all.css` — **не тронут** (проверено `git diff --stat` → пусто).

## Затронутые файлы

- `site/ui-kit/components/states.css` — new
- `site/assets/styles/app.css` — modified (+1 import)
- `site/ui-kit/storybook.html` — modified (+1 link)

## Self-review

- [x] Scope: только файл + два подключения, all.css чист
- [x] Ноль хардкод-цветов: `grep -nE '#hex|rgb'` по states.css → пусто
- [x] Ноль новых классов: только псевдоклассы на существующем `.input`
- [x] Токены существуют: `--input-bg/-text/-border-focus/-focus-ring` в `tokens/layout.css:63-70`
- [x] `check-ui-kit-classes.mjs`: вывод байт-идентичен master (diff против stash-baseline) — states.css не добавил ни одного нарушения; имеющиеся (btn-link, text-muted, mb-0 в legacy-местах) существовали до задачи
- [x] `check-uikit-react-mapping.mjs`: вывод байт-идентичен master (tree-picker ref-broken — pre-existing) — css без html-пары для маппера невидим, как и предсказала разведка
- [x] `npm run build` → green; в `app-HC0FEOkQ.css` autofill-правила на байте 66529/68878 — после всех ui-kit-правил, дальше только локальные правила app.css (`.page`, `.navbar-vertical`)

## Команды для проверки

- `cd site && node tools/check-ui-kit-classes.mjs; node tools/check-uikit-react-mapping.mjs`
- `cd site && npm run build && grep -c webkit-autofill public/build/assets/app-*.css`
- `grep -nE '#[0-9a-fA-F]{3,6}|rgb' site/ui-kit/components/states.css`

## Риски / на что смотреть ревьюеру

- Оба линтера падали (exit 1) и **до** задачи — нарушения legacy, не блокер этой ветки; зафиксировано diff-ом против baseline.
- `:autofill` (стандартный, без префикса) намеренно не добавлен: Firefox не поддерживает `-webkit-text-fill-color`-хак, а его autofill-стили слабее и не ломают токены. Добавим при реальной жалобе.

## Открытые вопросы

- нет
