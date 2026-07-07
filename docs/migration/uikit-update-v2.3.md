# UI Kit Update v2.1 → v2.3

**Date:** 2026-07-07
**Branch:** chore/uikit-update-v2.3
**Mode:** diff
**Source:** site/ui-kit/_incoming/ui-kit-v2.3.html
**Diff base:** site/ui-kit/_incoming/ui-kit.html (v2.1 — фактическая база split-структуры)

## Контекст: рассинхрон версий

Split-структура и `storybook.html` были на **v2.1**. Монолиты v2.2 и v2.3 лежали в
`_incoming/`, но **никогда не разносились** (коммиты «до v2.2»/«до v2.3» только
добавили файлы в `_incoming/`; миграционных доков v2.2/v2.3 нет). Скрипт из
`uikit-update.md` по умолчанию взял бы diff v2.2↔v2.3 и потерял бы правки
v2.1→v2.2. По решению Владельца обработка выполнена как **полный diff v2.1→v2.3
одним PR** (метка v2.3, changelog складывает обе версии).

## Classification

| # | Регион | Class | Description | Target file |
|---|---|---|---|---|
| 1 | `<style>` INPUT | ⚠️ deprecation | `.field` / `.field-label` помечены `@deprecated v2.3 → v3.0` (правила не тронуты) | components/input.css |
| 2 | `<style>` INPUT-WRAP | 🟡 component-add | `.input-wrap` формализован: display:flex, prefix/suffix inline-flex, `.input--prefix/--suffix` де-вложены; отдельный маркер | components/input-wrap.{css,html} + all.css |
| 3 | `<style>` FORM-FIELD | 🟡 component-add | Новый примитив `.form-field` / `.form-label` (v2.2) | components/form-field.{css,html} + all.css |
| 4 | body demos | 🟢 storybook-edit | Массовый перевод demo `.field`/`.field-label` → `.form-field`/`.form-label` (~20 блоков) | storybook.html |
| 5 | body decisions | 🟢 storybook-edit | Decision 23 (INPUT-WRAP), Decision 24 (deprecation .field) | storybook.html |
| 6 | body nav | 🟢 storybook-edit | nav-ссылки «Form field (new)», «Input wrap (new)» | storybook.html |
| 7 | head/body | 🟢 storybook-edit | Version bump + баннер canonical stylesheet | storybook.html |
| 8 | body changelog | 🟢 storybook-edit | Записи v2.2 и v2.3 | storybook.html |

Total: 8 групп изменений. 🔴 breaking — **нет**.

Токены (`:root`) — **без изменений**. `--form-label-text` / `--form-required-mark`
уже были в `tokens/layout.css` (объявлены с v2.0), новые компоненты их «оживляют».

## Version bump

v2.1 → v2.3 (minor — component-add: FORM-FIELD + INPUT-WRAP). Складывает v2.2 (FORM-FIELD)
и v2.3 (INPUT-WRAP formalization + deprecation). Breaking нет — старые `.field*` рабочие.

## New components

| Component | Files | React wrapper status |
|---|---|---|
| FormField | components/form-field.{html,css} | ⚠️ TODO — отдельная задача |
| InputWrap | components/input-wrap.{html,css} | ⚠️ TODO — отдельная задача |

## New tokens

Нет.

## Sections expected to differ visually

- **Forms → Form field** (новая секция): раньше отсутствовала.
- **Forms → Input wrap** (новая секция): раньше отсутствовала.
- Все demo-поля форм (Input, Select, Textarea, Search, Password, Radio, Tags, Report,
  Auth, модалки): визуально идентичны — сменился только класс обёртки `.field`→`.form-field`
  (оба layout-примитива дают одинаковый `display:flex; gap:5px`).
- **Decisions**: +23, +24. **Changelog**: +v2.2, +v2.3.
- Остальные секции (Buttons, KPI, Table, Money, Badge, …) — без изменений.

## Verification

- [x] Скриншоты — визуальная проверка Владельцем пройдена: split идентичен монолиту, расхождений нет (2026-07-07)
- [x] check-ui-kit-classes: 8989 → 8788 нарушений (−201: новые классы теперь распознаются), exit 0
- [x] check-uikit-react-mapping: 42 → 44 ref-broken-react-ref (+2 = FormField, InputWrap — ожидаемый backlog)
- [x] vite build: clean (собран в temp-outDir), app-*.css содержит `.form-field`/`.form-label`/`.input-wrap`/`.input--prefix`/`.input--suffix`

> **Build-примечание:** `npm run build` в дефолтный `public/build/` падает с EACCES —
> `public/build/.vite` и `assets` принадлежат `root` (docker-сборка от 30.06). Это
> окружение, не связано с правкой. Проверка выполнена сборкой в `/tmp` (exit 0, стили в бандле).
> Перед деплоем очистить `public/build/` от root-овнутых файлов (`sudo rm -rf site/public/build/*`).

## Follow-ups

1. React-обёртки для FormField и InputWrap (по образцу `uikit-button-wrapper.md`).
2. Постепенный перевод Twig-шаблонов с `.field`/`.field-label` на `.form-field`/`.form-label` (до v3.0).
3. Почистить root-овнутые файлы в `site/public/build/` (env-долг, к ui-kit не относится).
