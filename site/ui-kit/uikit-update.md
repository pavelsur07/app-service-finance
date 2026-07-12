# UI Kit Update — интеграция новой версии от дизайнера

> **Эта задача запускается каждый раз, когда дизайнер прислал новый монолитный `ui-kit.html`.**
> Цель — разнести правки по файлам `tokens/`, `components/`, `patterns/`, обновить `storybook.html` как shell, прогнать проверки, открыть PR.
> Без визуальной регрессии там, где ничего не должно было меняться.

---

## Контракт с дизайнером — Вариант 3 (`_incoming/`)

Дизайнер работает в **одном** монолите. Готовые версии кладёт в `site/ui-kit/_incoming/` с именем:

```
site/ui-kit/_incoming/ui-kit-v<версия>.html
```

Пример: `ui-kit-v2.1.html`, `ui-kit-v2.2.html`.

Эти файлы **не удаляются** после обработки — они остаются как исторический источник и как база для diff при следующих итерациях.

Прод собирается из `ui-kit/tokens/*`, `ui-kit/components/*`, `ui-kit/patterns/*` через `assets/styles/app.css`. Монолит из `_incoming/` для прода не используется.

---

## Когда запускать

- В `site/ui-kit/_incoming/` появился новый `ui-kit-v<N>.html`.
- Только по явному триггеру Владельца: `выполни uikit-update.md, версия v<N>`.

---

## Входы

| Что | Где | Обязательно |
|---|---|---|
| Новый монолит | `site/ui-kit/_incoming/ui-kit-v<NEW_VERSION>.html` | да |
| Предыдущий монолит (для diff) | `site/ui-kit/_incoming/ui-kit-v<OLD_VERSION>.html` | опционально, отсутствует при первом запуске |
| Текущее split-состояние | `site/ui-kit/{tokens,components,patterns}/`, `site/ui-kit/storybook.html` | да |

---

## Что эта задача делает и чего НЕ делает

**Делает:**

- Определяет режим: `diff-mode` (есть предыдущий монолит) или `full-replace-mode` (первый запуск).
- Классифицирует изменения (token / component / variant / breaking / storybook-only).
- Разносит правки по файлам `tokens/*.css`, `components/*.{html,css}`, `patterns/*.{html,css}`.
- Создаёт новые компоненты, если найдены.
- Перегенерирует `storybook.html` как shell.
- Обновляет версию и Changelog.
- Прогоняет линтеры и build.
- Открывает draft PR с отчётом.

**НЕ делает:**

- Не правит React-обёртки в `assets/react/ui-kit/`. Новые обёртки — отдельная задача.
- Не мигрирует Twig-шаблоны на новые классы.
- Не правит `_legacy/`.
- Не мерджит PR.
- Не выполняет breaking-изменения без явного STOP+апрува.
- Не добавляет npm-зависимости.
- Не удаляет и не изменяет файлы в `_incoming/` — они исторический архив.

---

## Pre-flight

1. На свежем master:
   ```bash
   git checkout master && git pull
   ```
2. `git status` чистый.

3. **Определить `NEW_VERSION`** — из файла в `_incoming/`:
   ```bash
   NEW_FILE=$(ls site/ui-kit/_incoming/ui-kit-v*.html 2>/dev/null | sort -V | tail -1)
   test -n "$NEW_FILE" || { echo "🛑 STOP: нет файла в _incoming/"; exit 1; }
   grep -E "UI Kit · Ваш Финдир v[0-9]" "$NEW_FILE" | head -1
   ```
   Из этой строки извлечь `NEW_VERSION` (например `v2.1`). Проверить, что версия в `<title>`, `.nav-version`, `.page-meta` внутри файла совпадает — иначе 🛑 STOP.

4. **Определить `OLD_VERSION`** — из shell в проде:
   ```bash
   grep -E "UI Kit · Ваш Финдир v[0-9]" site/ui-kit/storybook.html | head -1
   ```

5. **Определить режим работы:**
   ```bash
   ALL_FILES=$(ls site/ui-kit/_incoming/ui-kit-v*.html 2>/dev/null | sort -V)
   COUNT=$(echo "$ALL_FILES" | wc -l)
   if [ "$COUNT" -lt 2 ]; then
     MODE="full-replace"    # только один файл — первый запуск, diff не с чем
     PREV_FILE=""
   else
     PREV_FILE=$(echo "$ALL_FILES" | tail -2 | head -1)
     MODE="diff"
   fi
   echo "MODE=$MODE, PREV=$PREV_FILE, NEW=$NEW_FILE"
   ```

    - **`diff-mode`** — сравнение `_incoming/ui-kit-v<OLD>.html` ↔ `_incoming/ui-kit-v<NEW>.html`. Точечные правки по hunks.
    - **`full-replace-mode`** — предыдущего монолита нет. Полная замена split-структуры на основе `_incoming/ui-kit-v<NEW>.html`. Diff между shell-storybook и монолитом **не делать** — это не будет содержательный diff (в shell нет `:root` и правил компонентов, всё «уедет как новое»).

6. Сохранить рабочие копии для обработки:
   ```bash
   cp "$NEW_FILE" /tmp/storybook-new.html
   [ "$MODE" = "diff" ] && cp "$PREV_FILE" /tmp/storybook-old.html
   ```

7. Создать рабочую директорию для аудита:
   ```bash
   mkdir -p site/ui-kit/_audit/update-$(date +%F)/{before,after}
   ```

---

## Phase 1 — Diff (только `diff-mode`)

Если `MODE=full-replace` — пропустить, перейти к Phase 1'.

```bash
diff -u /tmp/storybook-old.html /tmp/storybook-new.html > /tmp/storybook.diff
wc -l /tmp/storybook.diff
```

Классифицировать каждое изменение:

| # | Hunk (line range) | Class | Описание |
|---|---|---|---|
| 1 | L173-180 (`:root`) | 🟢 token-change | `--color-primary: #1A56DB → #1A57DC` |
| 2 | L420-450 (`.btn-xxl` block) | 🟡 variant-add | Новый размер кнопки `xxl` |
| 3 | L1234-1290 (новая секция) | 🟡 component-add | Новый компонент `Notification` |
| 4 | L1900-1905 (`.chip` rename) | 🔴 breaking-rename | `.chip` → `.chip--filter` |
| 5 | L3450 (changelog) | 🟢 storybook-edit | Запись о v<NEW_VERSION> |

Если хоть один hunk — 🔴 breaking → 🛑 **STOP**, доложить Владельцу с описанием.

## Phase 1' — Full inventory (только `full-replace-mode`)

Пройти новый монолит целиком и **инвентаризовать** содержимое (это заменяет diff):

- Все токены в `:root` — целиком.
- Все маркеры `/* ============ NAME ============ */` — построить список компонентов.
- Все `<section id="...">` в `<body>` — проверить парность маркер ↔ секция.
- Все decisions, changelog-блоки.

Сохранить как «полный inventory» — это то, что будет разложено по файлам в Phase 3.

---

## Phase 2 — Определить целевую версию

`NEW_VERSION` уже известен из Pre-flight. Проверить, что он согласован с типом изменений по semver:

| Если в diff/inventory есть | Ожидаемая версия |
|---|---|
| Только 🟢 storybook-edit | без bump |
| 🟢 token-change или 🟢 component-edit | patch (v1.4.0 → v1.4.1) |
| 🟡 component-add или 🟡 variant-add | minor (v1.4.0 → v1.5.0) |
| 🔴 breaking-* | major (v1.4 → v2.0) — только с апрувом Владельца |
| Decision, меняющий политику (именование, semver, конвенции) | major с апрувом |

Если фактический `NEW_VERSION` **не соответствует** реальным изменениям (например, добавили компонент, но версия patch) — 🛑 STOP, спросить Владельца.

---

## Phase 3 — Применить правки

Создать рабочую ветку:
```bash
git checkout -b chore/uikit-update-<NEW_VERSION>
```

### 3.1. Token changes

Правки `:root { ... }` разносить по файлам:
- Цвет → `ui-kit/tokens/colors.css`
- Шрифт → `ui-kit/tokens/typography.css`
- Spacing → `ui-kit/tokens/spacing.css`
- Radius → `ui-kit/tokens/radius.css`
- Shadow → `ui-kit/tokens/shadows.css`
- Layout (avatar, toggle, modal, drawer, app-header) → `ui-kit/tokens/layout.css`
- Semantic (`--button-*`, `--card-*`, etc.) → `ui-kit/tokens/semantic.css`

В `full-replace-mode` — заменить содержимое файлов целиком, разложив `:root` монолита по 7 файлам.

### 3.2. Component edits

Правки CSS-правил существующего компонента → `ui-kit/components/<name>.css`.
Правки HTML-примера → `ui-kit/components/<name>.html`.

### 3.3. Component-add (обязательный подшаг all.css)

Новый компонент = **три обязательных действия**:

1. Создать `ui-kit/components/<name>.html` с frontmatter:
   ```html
   <!-- @react <ComponentName> -->
   <!-- @uiKit v<NEW_VERSION> -->
   <!-- @docs ui-kit/components/<name>.css -->

   <!--
     <ComponentName> — описание из storybook demo.
     Variants: … / Sizes: … / States: …
   -->

   <!-- demo markup -->
   ```

2. Создать `ui-kit/components/<name>.css` с CSS-правилами.

3. **Обязательно** добавить в `ui-kit/components/all.css`:
   ```css
   @import url('./<name>.css');
   ```
   С сохранением **алфавитного порядка**. Без этого шага компонент не попадёт в бандл — прод его не увидит.

Для паттернов — аналогично в `ui-kit/patterns/` и `ui-kit/patterns/all.css`.

### 3.4. Variant-add

Новый модификатор (например, `.btn-xxl`):
1. Добавить CSS-правило в существующий `<name>.css`.
2. Добавить пример в `<name>.html`.
3. Обновить frontmatter — дописать новый вариант в комментарий.

### 3.5. Storybook-edit

Правки storybook-shell (nav, decision-блоки, swatches, changelog) применяются к `storybook.html` в Phase 4.

---

## Phase 4 — Перегенерировать storybook.html

Взять `<body>` из `/tmp/storybook-new.html` целиком. Собрать `ui-kit/storybook.html` заново:

1. В `<head>`:
   ```html
   <link rel="preconnect" href="https://fonts.googleapis.com">
   <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
   <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap">
   <link rel="stylesheet" href="tokens/index.css">
   <link rel="stylesheet" href="components/all.css">
   <link rel="stylesheet" href="patterns/all.css">
   <link rel="stylesheet" href="components/states.css"><!-- behavior layer, cascade-last (owner=code) -->
   ```

2. В `<style>` оставить **только** storybook-shell:
    - `.layout`, `.nav`, `.main`, `.cb`, `.demo`, `.swatch`, `.type-row`, `.cl-*`, `.decision-*`, `.u-*` utility.

3. Все `:root { ... }`, `.btn`, `.input`, `.kpi`, `.status` и т.д. в `<style>` **не должны** остаться — они уже в файлах Phase 3.

4. `<body>` — из монолита дизайнера (там demo-блоки для всех компонентов).

5. Версия обновлена в трёх местах:
    - `<title>UI Kit · Ваш Финдир v<NEW_VERSION></title>`
    - `<div class="nav-version">v<NEW_VERSION> · <дата></div>`
    - `<div class="page-meta">v<NEW_VERSION> · <дата> · Светлая тема</div>`

---

## Phase 5 — Changelog

Добавить запись в начало Changelog-секции `storybook.html`:

```html
<div class="cb">
  <div class="cl-version">v<NEW_VERSION> — <YYYY-MM-DD></div>
  <div class="cl-summary"><краткое описание одной строкой></div>
  <ul class="cl-list">
    <li>➕ <component-add: что добавлено></li>
    <li>🎨 <token-change: что изменено></li>
    <li>🔧 <component-edit: что починено></li>
  </ul>
</div>
```

Эмодзи-конвенция:
- ➕ добавлено
- 🎨 визуальное изменение
- 🔧 исправление
- 🧹 чистка
- 📐 архитектурное решение
- ⚠️ deprecation

---

## Phase 6 — Verify

### 6.1. Скриншоты — ручной шаг Владельца

**Этот шаг задача НЕ автоматизирует.**

Владелец сам:
1. Открывает `ui-kit/storybook.html` локально через HTTP-сервер.
2. Сравнивает визуально с `_incoming/ui-kit-v<NEW>.html` (открытый как есть, монолит).
3. Разные версии секций — ожидаемо в местах, где были правки. Идентичны в остальных.
4. При расхождении в неизменённых секциях — комментарий в PR, разбор.
5. **Behavior layer**: расхождения между storybook и монолитом, вызванные `components/states.css` (autofill, native pseudo-classes, focus), — **ОЖИДАЕМЫ**: в монолите дизайнера этого слоя нет и не будет. Не флагать как регрессию.

Задача Claude Code только создаёт папки `_audit/update-<DATE>/{before,after}/` как маркер и коротко описывает в отчёте, где ожидаются изменения. Скриншоты не делает.

### 6.2. Линтеры

```bash
cd site

node tools/check-ui-kit-classes.mjs
# Ожидаемо: количество найденных классов выросло на N (новые классы из Phase 3).
# Нарушений в проекте — 0.

node tools/check-uikit-react-mapping.mjs
# Ожидаемо: ref-no-react-mapping вырос на K (K = новые component-add).
# Backlog для отдельной задачи, не блокер.
```

### 6.3. Build

```bash
cd site
npm run build
```

Exit 0, без ошибок. UI Kit CSS вошёл в `public/build/app-*.css`. Размер `app-*.css` вырос на объём новых компонентов.

---

## Phase 7 — Отчёт + PR

Создать `docs/migration/uikit-update-<NEW_VERSION>.md`:

```markdown
# UI Kit Update <OLD_VERSION> → <NEW_VERSION>

**Date:** <YYYY-MM-DD>
**Branch:** chore/uikit-update-<NEW_VERSION>
**Mode:** diff | full-replace
**Source:** site/ui-kit/_incoming/ui-kit-v<NEW_VERSION>.html

## Classification

| # | Hunk | Class | Description | Target file |
|---|---|---|---|---|
| 1 | … | 🟢 token-change | … | tokens/colors.css |
| 2 | … | 🟡 component-add | … | components/notification.* |

Total: <N> изменений.

## Version bump

<OLD_VERSION> → <NEW_VERSION> (<patch | minor | major>)

Reason: <тип изменения с наибольшим весом>.

## New components (if any)

| Component | Files | React wrapper status |
|---|---|---|
| Notification | components/notification.{html,css} | ⚠️ TODO — отдельная задача |

## New tokens (if any)

| Token | File | Value |
|---|---|---|
| --notification-bg | tokens/colors.css | #FEF3C7 |

## Sections expected to differ visually

- <секция>: причина
- …

## Verification

- [ ] Скриншоты — ручная проверка Владельцем при review PR
- [x] check-ui-kit-classes: <N1> → <N2> классов, 0 нарушений
- [x] check-uikit-react-mapping: <M1> → <M2> ref-no-react-mapping (ожидаемо)
- [x] npm run build: clean, app-*.css bundle includes new styles

## Follow-ups

1. React-обёртки для <список новых компонентов>.
2. …
```

Коммит и draft PR:

```bash
git add site/ui-kit/ docs/migration/
git commit -m "chore(ui-kit): update to v<NEW_VERSION>

<one-line summary>

Mode: <diff | full-replace>
- <N hunks classified, all non-breaking>
- <K new components, M new tokens>
- React wrappers for new components: TODO (separate task)"

git push -u origin chore/uikit-update-<NEW_VERSION>

gh pr create --draft \
  --title "chore(ui-kit): update to v<NEW_VERSION>" \
  --body-file docs/migration/uikit-update-<NEW_VERSION>.md
```

---

## Self-review

- [ ] `_incoming/ui-kit-v<NEW>.html` найден, версия распарсена
- [ ] `MODE` определён (diff | full-replace)
- [ ] В `diff-mode`: все hunks классифицированы, ни один не пропущен
- [ ] Нет 🔴 breaking (или задача остановлена с апрувом)
- [ ] Каждое изменение применено к правильному файлу:
    - токены → `tokens/*.css`
    - правки компонента → `components/<name>.css`
    - новый компонент → пара `components/<name>.{html,css}` **+ запись в `all.css`**
- [ ] `storybook.html` — shell, без `:root` и компонентного CSS
- [ ] Версия обновлена в 3 местах
- [ ] Запись в Changelog добавлена
- [ ] `check-ui-kit-classes` — 0 нарушений
- [ ] `check-uikit-react-mapping` — рост соответствует количеству новых компонентов
- [ ] `npm run build` — green
- [ ] Отчёт `docs/migration/uikit-update-<NEW_VERSION>.md` создан
- [ ] Draft PR открыт
- [ ] `components/states.css` существует, апдейтом не изменён, импортируется из `assets/styles/app.css` последним в блоке импортов (не из all.css)
- [ ] Файлы в `_incoming/` не тронуты
- [ ] Никакие файлы вне `ui-kit/` и `docs/migration/` не правились
- [ ] `_legacy/`, `assets/react/`, `templates/`, `src/` не тронуты

---

## Что НИКОГДА не делать

```
breaking-changes без апрува                          — STOP
React-обёртки в этой задаче                          — другая задача
Twig-шаблоны под новые классы                        — другая задача
удалять или переименовывать файлы в _incoming/       — это архив
удалять токены без проверки использований            — STOP
самостоятельный merge PR                             — только Владелец
пропускать обязательный шаг all.css для нового       — компонент не попадёт в бандл
менять semver-правила «как удобнее»                  — следовать таблице
делать скриншоты вручную от имени задачи             — это ручной шаг Владельца
коммитить /tmp/storybook-*.html                      — рабочие файлы
регенерить или перезаписывать components/states.css  — code-owned behavior layer, апдейт его не трогает
```

---

## Closing

🛑 STOP. Draft PR открыт. Ждать Владельца:

1. Ревью кода и отчёта.
2. Ручная визуальная проверка `storybook.html` vs монолит.
3. Перевод PR из draft в ready.
4. Merge.

После merge — если были новые компоненты, отдельная задача на React-обёртки по образцу `uikit-button-wrapper.md`.

---

## Rollback

Если что-то пошло не так до push:
```bash
git reset --hard origin/master
git branch -D chore/uikit-update-<NEW_VERSION>
rm /tmp/storybook-old.html /tmp/storybook-new.html /tmp/storybook.diff
```

`_incoming/` при rollback **не трогается** — это исторический архив, живёт отдельно от split-структуры.

Если после merge обнаружена регрессия:
```bash
git revert <merge-commit-sha>
```

Атомарный PR — один revert возвращает предыдущую версию split-структуры без побочных эффектов.
