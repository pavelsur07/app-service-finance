# UI Kit Split — разбор storybook.html на файлы

> Механическое разделение `site/ui-kit/storybook.html` на:
> - `site/ui-kit/tokens/*.css` — дизайн-токены (CSS-переменные)
> - `site/ui-kit/components/*.{html,css}` — примитивы
> - `site/ui-kit/patterns/*.{html,css}` — композиты
>
> `storybook.html` после разбора подключает их через `<link>` и продолжает рендериться идентично.

---

## Цель

После выполнения:

1. `ui-kit/tokens/` — 7 файлов с переменными.
2. `ui-kit/components/` — отдельные файлы per primitive (Button, Input, Card, ...).
3. `ui-kit/patterns/` — отдельные файлы per pattern (KpiRow, FilterBar, ...).
4. `ui-kit/storybook.html` — теперь shell, который импортирует всё через `<link>`. Визуально не отличим от текущего.
5. `assets/styles/app.css` подключает токены и компоненты UI Kit — единый источник стилей для прода.

**Визуальная регрессия — недопустима.** Скриншоты до/после = идентичны.

---

## Что НЕ делать

- Не переименовывать CSS-классы.
- Не менять токены (значения, имена).
- Не добавлять/убирать компоненты.
- Не править React, Twig, Symfony.
- Не подключать новые npm-зависимости.
- Не делать PostCSS, Sass, Tailwind — это plain CSS, как было.

---

## Pre-flight

1. `git status` чистый. Иначе 🛑 STOP.
2. На свежем `master`.
3. `cp ui-kit/storybook.html ui-kit/storybook.html.bak` — страховка.
4. Сделать скриншоты текущего `storybook.html` в браузере по якорям секций (`#decisions`, `#colors`, `#typography`, `#spacing`, `#radius`, `#shadows`, `#button`, `#input`, `#badge`, `#status`, `#avatar`, `#toggle`, `#tags`, `#table`, `#kpi`, `#report`, `#acc-card`, `#card`, `#menu`, `#tabs`, `#sidebar`, `#entity-picker`, `#tree-picker`, `#drawer`, `#modal`, `#empty`, `#direction`, `#toast`, `#alert`, `#confirm`, `#money`, `#patterns`, `#changelog`). Сохранить в `ui-kit/_audit/before-split/`.
5. Прочитать `<style>...</style>` из `storybook.html` целиком в память — это рабочий материал.

---

## Целевая структура

```
ui-kit/
├── tokens/
│   ├── colors.css         — Brand, Text, Borders, Backgrounds, Semantic (success/danger/warning/neutral), Banks, Source badges, Categories, State colors (audit v1.4), Focus/glow
│   ├── typography.css     — --font-family, --font-mono, --font-*
│   ├── spacing.css        — --s-1...8
│   ├── radius.css         — --r-1...4, --r-pill, --r-circle
│   ├── shadows.css        — --shadow-*
│   ├── layout.css         — --card-padding, --drawer-width, --modal-width-*, --backdrop-*, --avatar-*, --toggle-*
│   ├── semantic.css       — Semantic tokens (button-*, input-*, card-*, form-*, status-*)
│   └── index.css          — @import всех файлов выше
├── components/
│   ├── button.html + button.css
│   ├── input.html + input.css
│   ├── badge.html + badge.css      (chip, src, bank-badge)
│   ├── status.html + status.css
│   ├── avatar.html + avatar.css
│   ├── toggle.html + toggle.css
│   ├── table.html + table.css
│   ├── kpi.html + kpi.css
│   ├── menu.html + menu.css         (dropdown)
│   ├── tabs.html + tabs.css
│   ├── toast.html + toast.css
│   ├── alert.html + alert.css
│   ├── confirm.html + confirm.css
│   ├── money.html + money.css
│   ├── direction.html + direction.css
│   ├── card.html + card.css         (общий card)
│   └── all.css                      — @import всех компонентов
├── patterns/
│   ├── sidebar.html + sidebar.css
│   ├── picker.html + picker.css     (EntityPicker + TreePicker — общий picker.css)
│   ├── tags.html + tags.css
│   ├── report.html + report.css     (грид-отчёт)
│   ├── drawer.html + drawer.css
│   ├── modal.html + modal.css
│   ├── empty-state.html + empty-state.css
│   ├── acc-card.html + acc-card.css (account card)
│   └── all.css                      — @import всех паттернов
├── storybook.html                   ← shell: подключает токены/компоненты/паттерны через <link>
├── _audit/
│   ├── before-split/
│   └── after-split/
└── ...                              (decisions.md, CHANGELOG.md, README.md — не трогать)
```

Разделение «component vs pattern»:

- **Component** = атом UI Kit, мелкий, переиспользуемый в чистом виде. Button, Input, Status, Avatar, Toggle.
- **Pattern** = композиция или сложный визуальный блок, обычно специфичный для приложения. Sidebar, Picker, Report, Drawer, Modal.

Если сомневаешься — кладёшь в `components/`. Лучше плоско, чем перемудрить.

---

## Phase 1 — Извлечение токенов

Текущий CSS начинается с `:root { ... }`. Этот блок разбить на 7 файлов по разделам, которые **уже** размечены комментариями `/* ===== ... ===== */` в файле:

| Раздел в `:root` | Файл |
|---|---|
| Brand + Text + Borders + Backgrounds + Semantic (success/danger/warning/neutral) + Banks + Source badges + Categories + State colors (audit v1.4) + Focus/glow | `ui-kit/tokens/colors.css` |
| Typography | `ui-kit/tokens/typography.css` |
| Spacing | `ui-kit/tokens/spacing.css` |
| Radii | `ui-kit/tokens/radius.css` |
| Shadows | `ui-kit/tokens/shadows.css` |
| Layout tokens + Avatar sizes + Toggle | `ui-kit/tokens/layout.css` |
| SEMANTIC TOKENS (component-facing) | `ui-kit/tokens/semantic.css` |

Каждый файл — отдельный `:root { ... }` с **только** своими переменными. Пример:

```css
/* ui-kit/tokens/colors.css */
:root {
  /* ===== Brand ===== */
  --color-primary: #1A56DB;
  --color-primary-hover: #1E40AF;
  /* ... остальное из секции Brand ... */

  /* ===== Text ===== */
  --text-strong: #0B1220;
  /* ... */

  /* (и так далее — все цвета в одном файле) */
}
```

Создать `ui-kit/tokens/index.css`:

```css
@import url('./colors.css');
@import url('./typography.css');
@import url('./spacing.css');
@import url('./radius.css');
@import url('./shadows.css');
@import url('./layout.css');
@import url('./semantic.css');
```

**Важно:** порядок имеет значение. `semantic.css` использует переменные из `colors.css` — должен импортироваться **после**. Порядок в `index.css` выше — корректный.

---

## Phase 2 — Извлечение компонентов

В текущем `<style>` каждый компонент уже размечен комментарием вида `/* ============ BUTTONS ============ */`. Это границы блоков.

Для каждого компонента создать **два** файла:

### 2.1. `<name>.css`

Содержит CSS-правила компонента дословно из `storybook.html`. Без правок.

Пример:

```css
/* ui-kit/components/button.css */
/* ============ BUTTONS ============ */
.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 7px;
  /* ... остальное из исходника ... */
}
.btn-sm { ... }
.btn-md { ... }
.btn-lg { ... }
.btn-primary { ... }
/* и так далее, ВСЕ селекторы из секции BUTTONS */
```

### 2.2. `<name>.html`

Содержит примеры разметки из секции `id="button"` в `storybook.html` (или соответствующего раздела). Frontmatter:

```html
<!-- @react Button -->
<!-- @uiKit v1.4 -->
<!-- @docs ui-kit/components/button.css -->

<!--
  Button — основной компонент действия.

  Variants: primary | secondary | ghost | danger | danger-solid | warning-solid
  Sizes:    sm | md | lg
  States:   default | hover | active | focus | disabled | loading
-->

<!-- Variants -->
<button class="btn btn-primary btn-md">Primary</button>
<button class="btn btn-secondary btn-md">Secondary</button>
<button class="btn btn-ghost btn-md">Ghost</button>
<button class="btn btn-danger btn-md">Danger</button>

<!-- Sizes -->
<button class="btn btn-primary btn-sm">Small</button>
<button class="btn btn-primary btn-md">Medium</button>
<button class="btn btn-primary btn-lg">Large</button>

<!-- States -->
<button class="btn btn-primary btn-md" disabled>Disabled</button>
<button class="btn btn-primary btn-md btn-loading">Loading</button>
```

**Контент HTML** копируется **дословно** из соответствующей `<section id="...">` в `storybook.html`, но без оборачивающих `.cb`/`.demo` контейнеров (это discovery-разметка storybook'а, не сам компонент).

Маппинг секций → файлы:

| Секция в storybook.html | components/ |
|---|---|
| `id="button"` + `/* BUTTONS */` | button |
| `id="input"` + `/* INPUTS */` | input |
| `id="badge"` + `/* BADGES / CHIPS */` (chip + src + bank-badge) | badge |
| `id="status"` + `/* STATUS PILL */` | status |
| `id="avatar"` + `/* AVATAR */` | avatar |
| `id="toggle"` + `/* TOGGLE */` | toggle |
| `id="table"` + `/* TABLE */` | table |
| `id="kpi"` + `/* KPI */` | kpi |
| `id="menu"` + `/* DROPDOWN MENU */` | menu |
| `id="tabs"` + `/* TABS */` | tabs |
| `id="toast"` + `/* TOAST */` | toast |
| `id="alert"` + `/* INLINE ALERT */` | alert |
| `id="confirm"` + `/* CONFIRMATION DIALOG */` | confirm |
| `id="money"` + `/* MONEY */` | money |
| `id="direction"` + `/* DIRECTION */` | direction |
| `id="card"` (если есть отдельная секция; иначе пропустить — `.card` стилизуется через token) | card |

Создать `ui-kit/components/all.css`:

```css
@import url('./button.css');
@import url('./input.css');
@import url('./badge.css');
@import url('./status.css');
@import url('./avatar.css');
@import url('./toggle.css');
@import url('./table.css');
@import url('./kpi.css');
@import url('./menu.css');
@import url('./tabs.css');
@import url('./toast.css');
@import url('./alert.css');
@import url('./confirm.css');
@import url('./money.css');
@import url('./direction.css');
@import url('./card.css'); /* если создан */
```

---

## Phase 3 — Извлечение паттернов

Аналогично Phase 2, но в `ui-kit/patterns/`. Маппинг:

| Секция | patterns/ |
|---|---|
| `id="sidebar"` + `/* SIDEBAR */` | sidebar |
| `id="entity-picker"` + `id="tree-picker"` + `/* EntityPicker / TreePicker shared */` | picker |
| `id="tags"` + `/* Tags input */` | tags |
| `id="report"` + `/* REPORT (period grid) */` | report |
| `id="drawer"` (если есть стили в storybook) | drawer |
| `id="modal"` | modal |
| `id="empty"` | empty-state |
| `id="acc-card"` (account card) | acc-card |

Если для секции **нет** уникальных стилей в текущем CSS (например, `drawer` использует только токены `--drawer-width` без своих CSS-правил) — создать только `.html` с примерами, `.css` не нужен, и в `all.css` не импортировать.

Создать `ui-kit/patterns/all.css` по аналогии.

---

## Phase 4 — Storybook.html как shell

После Phase 1–3 переписать `ui-kit/storybook.html`:

### 4.1. `<head>`

```html
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>UI Kit · Ваш Финдир v1.4</title>

  <!-- Шрифты (как в v1.4) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap">

  <!-- UI Kit -->
  <link rel="stylesheet" href="tokens/index.css">
  <link rel="stylesheet" href="components/all.css">
  <link rel="stylesheet" href="patterns/all.css">

  <!-- Storybook-only стили (layout страницы, nav, swatches, type-row, etc.) -->
  <style>
    /* ============ LAYOUT ============ */
    /* ... оставляем стили навигации storybook'а, .cb, .demo, .swatch и т.п. ... */
  </style>
</head>
```

### 4.2. `<style>` в storybook.html содержит ТОЛЬКО storybook-specific

После выноса в файлы — в `<style>` остаются только стили, которые **не относятся** к компонентам UI Kit, а нужны только для самой страницы storybook:

- `.layout`, `.nav`, `.nav-brand`, `.nav-logo`, `.nav-title`, `.nav-version`, `.nav-section`, `.nav a`
- `.main`, `section`, `h1.page-h1`, `.page-sub`, `.page-meta`, `h2.section-h`, `.section-sub`, `h3.sub-h`, `h4.mini-h`
- `.cb`, `.cb-head`, `.cb-name`, `.cb-tag`, `.cb-when` (CARD-BLOCK pattern для документации)
- `.demo`, `.demo--column`, `.demo--center`, `.demo-label`
- `.grid-swatches`, `.swatch`, `.swatch-color`, `.swatch-info`, `.swatch-name`, `.swatch-token`, `.swatch-hex`
- `.type-row`, `.type-meta`
- `.cl-version`, `.cl-summary`, `.cl-list` (Changelog)
- `.decision-*`, `.code-inline`, `.row-2col`, `.row-3col`
- Любые `.is-hover`, `.is-active`, `.is-focus` state-mocks для showcase

**Все** остальные правила (`.btn`, `.input`, `.chip`, `.status`, `.kpi`, `.menu`, `.sb-*`, `.picker-*`, `.report` / `.r-*`, etc.) уходят в `components/` или `patterns/`.

### 4.3. `<body>` не меняется

Вся разметка storybook'а (nav, секции, демо-блоки) остаётся как есть. Только теперь стили подгружаются из файлов.

---

## Phase 5 — Интеграция с проектом

В проекте уже есть `site/assets/styles/`. Создать (или обновить) `site/assets/styles/app.css`:

```css
/* Глобальные стили проекта */
@import url('../../../ui-kit/tokens/index.css');
@import url('../../../ui-kit/components/all.css');
@import url('../../../ui-kit/patterns/all.css');

/* Если в проекте есть собственные глобальные стили — оставить здесь */
```

Пути с `../../../` — от `site/assets/styles/app.css` до `ui-kit/`. Если структура другая (например, `ui-kit/` лежит внутри `site/`) — поправить пути.

**Не править** `vite.config.js`, не править существующие entry. Только если `app.css` ещё не подключён в Twig — это **не задача этого PR**, добавить в Open issues.

Проверка после интеграции:
```bash
cd site
npm run build
```

Должно собраться без ошибок. Vite разрезолвит `@import url(...)` цепочки.

---

## Phase 6 — Verify (критично)

### 6.1. Storybook визуальная регрессия

1. Открыть `ui-kit/storybook.html` в браузере.
2. Сделать скриншоты тех же секций, что в pre-flight, в `ui-kit/_audit/after-split/`.
3. Сравнить попарно `before-split/` ↔ `after-split/`. **Идентично** = OK.
4. Если есть разница — выяснить причину, поправить. Никаких регрессий.

### 6.2. Прод (если в Twig подключён `app.css`)

Открыть локально dev-сервер, прогнать smoke по 9 URL из миграционного PR (`/dashboard`, `/marketplace-analytics`, и т.д.). Визуально — идентично проду.

### 6.3. CSS-классы доступны линтеру

```bash
cd site
node tools/check-ui-kit-classes.mjs
```

Скрипт уже умеет читать `ui-kit/components/*.css` и `ui-kit/patterns/*.css` — должен показать **больше** найденных классов, чем до разбора (потому что теперь они в отдельных файлах с лучшей структурой). Нарушений быть не должно — `_legacy/` исключён из его scope.

---

## Self-review (перед commit)

- [ ] `ui-kit/tokens/` содержит 7 файлов + `index.css`
- [ ] `ui-kit/tokens/index.css` импортирует все 7 в правильном порядке (`semantic.css` последним)
- [ ] Каждый файл `tokens/*.css` — один блок `:root { ... }`, только его переменные
- [ ] `ui-kit/components/` содержит N файлов `*.css` + N файлов `*.html` + `all.css`
- [ ] Каждый `components/*.html` имеет frontmatter `<!-- @react ... -->` + `<!-- @uiKit v1.4 -->` + `<!-- @docs ... -->`
- [ ] `ui-kit/patterns/` содержит свой набор файлов + `all.css`
- [ ] `ui-kit/storybook.html`:
  - в `<head>` подключены `tokens/index.css`, `components/all.css`, `patterns/all.css`
  - в `<style>` остались **только** storybook-specific правила
  - body не изменился
- [ ] `assets/styles/app.css` (или эквивалент) подключает `ui-kit/`
- [ ] `npm run build` — green (если интеграция в Vite сделана)
- [ ] Скриншоты `_audit/before-split/` и `_audit/after-split/` идентичны попарно
- [ ] `node tools/check-ui-kit-classes.mjs` — 0 нарушений
- [ ] `node tools/check-uikit-react-mapping.mjs` — выдаёт список `ref-no-react-mapping` (это **ожидаемо**: HTML-файлы созданы, React-обёрток ещё нет; это станет TODO для следующего PR)
- [ ] Никакие токены не переименованы
- [ ] Никакие CSS-классы не переименованы
- [ ] Backup `storybook.html.bak` удалён перед коммитом
- [ ] `git status`: только новые файлы в `ui-kit/tokens/`, `ui-kit/components/`, `ui-kit/patterns/`, изменённый `storybook.html`, изменённый `assets/styles/app.css`. Ничего больше.

---

## Что НИКОГДА не делать

```
переименовывать классы                          — никогда
менять значения токенов                         — никогда
объединять токены                               — никогда
удалять токены                                  — никогда
менять разметку компонентов в storybook         — никогда
менять order/состав /^.cb-/.demo/.swatch        — никогда
менять Vite-entries                             — другая задача
менять Twig                                     — другая задача
писать React-обёртки                            — другая задача
mergить PR автоматически                        — только Владелец
```

---

## Commit + draft PR

```bash
git checkout -b chore/uikit-split

git add ui-kit/tokens/ ui-kit/components/ ui-kit/patterns/
git add ui-kit/storybook.html
git add assets/styles/app.css   # если правился

git commit -m "chore(ui-kit): split storybook.html into tokens/ components/ patterns/

No visual changes. Storybook now loads tokens and components via <link>.
Visual regression verified via screenshots in ui-kit/_audit/.

Enables per-component versioning and react wrapper mapping
(via check-uikit-react-mapping.mjs)."

git push -u origin chore/uikit-split

gh pr create --draft \
  --title "chore(ui-kit): split storybook.html into files" \
  --body "..."
```

Тело PR:

```markdown
# UI Kit Split

Разбивает `ui-kit/storybook.html` на:
- `ui-kit/tokens/*.css` (7 файлов с переменными)
- `ui-kit/components/*.{html,css}` (N примитивов)
- `ui-kit/patterns/*.{html,css}` (M композитов)

`storybook.html` теперь shell, подключает их через `<link>`.

## Verification

- [x] Скриншоты `_audit/before-split/` vs `_audit/after-split/` — идентичны
- [x] `npm run build` — green
- [x] `check-ui-kit-classes.mjs` — 0 violations
- [ ] `check-uikit-react-mapping.mjs` — выдаёт список «нет React-обёрток», это ожидаемо

## Что НЕ изменилось

- Ни одно значение токена.
- Ни одно имя CSS-класса.
- Ни одна разметка компонентов в storybook'е.
- Ни один файл в `assets/`, `templates/`, `src/`.

## Next steps (отдельные PR)

1. Первая React-обёртка `Button` в `assets/react/ui-kit/Button/`.
2. Подключение `app.css` в Twig base layout (если ещё не).
3. Постепенная миграция компонентов на semantic-токены (Decision 19).
```

🛑 STOP. Ждать Владельца: ручной review, скриншот-сравнение, мерж.

---

## Rollback

Если что-то пошло не так:

```bash
# До push:
git reset --hard origin/master
git branch -D chore/uikit-split

# После merge:
git revert <merge-commit-sha>
```

Атомарный PR. Один revert возвращает в исходное состояние.
