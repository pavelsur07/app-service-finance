# Screen Intake — разбор HTML-макетов из screens/ в Twig + CSS

> **Постоянная задача.** Запускается каждый раз, когда в `site/screens/` появляется новый `.html`-макет от дизайнера.
> Превращает статичный макет на UI Kit классах в Twig-шаблон + page-specific CSS + Vite entry.
> Генерирует **только верстку**. Symfony-биндинги (csrf, роуты, ошибки) — ручная доводка разработчиком.

---

## Когда запускать

- В `site/screens/` появился новый `<name>.html` (не в `_analysis/`, не в `_archive/`).
- Только по явному триггеру: `выполни screen-intake.md, экран <name>`.

---

## Что делает / чего НЕ делает

**Делает:**

- Читает макет `screens/<name>.html`.
- Разбирает на `<style>`, `<body>`, `<script>`.
- Валидирует все CSS-классы: UI Kit / page-specific / STOP при чужом.
- Выносит `<style>` в `assets/styles/pages/<name>.css`.
- Регистрирует Vite entry `<name>`.
- Генерирует Twig-шаблон из `<body>`.
- Помечает интерактив дизайнера (`sc-if`, toggle) как `{# TODO #}`.
- Прогоняет build + линтеры.
- Пишет отчёт в матрицу `screens/README.md`.
- Открывает draft PR.

**НЕ делает:**

- Не генерирует Symfony-биндинги (`csrf_token`, `path()`, `error`, `last_username`, `app.user`). Оставляет плейсхолдеры + TODO.
- Не пишет Stimulus-контроллеры. Помечает TODO.
- Не переделывает `security/base.html.twig` и другие layout — они правятся вручную.
- Не перезаписывает существующий Twig — генерит `.new`.
- Не мигрирует легаси Tabler-страницы (это другая задача).
- Не мерджит PR.
- Не добавляет npm-зависимости.

---

## Контракт макета (что дизайнер обязан указать)

В начале `<body>` макета — мета-комментарий с целевым путём:

```html
<!-- @twig templates/security/login.html.twig -->
```

Без него задача не знает, куда генерить Twig → 🛑 STOP.

Опционально — page-prefix, если он не совпадает с именем файла:

```html
<!-- @prefix login -->
```

По умолчанию prefix = имя файла без расширения (`login.html` → `login-`).

---

## Pre-flight

1. На свежем master:
   ```bash
   git checkout master && git pull
   git status   # чисто, иначе STOP
   ```

2. Найти целевой макет:
   ```bash
   SCREEN=<name>
   test -f site/screens/$SCREEN.html || { echo "🛑 STOP: нет screens/$SCREEN.html"; exit 1; }
   ```

3. Проверить, что макет не в служебных папках (не `_analysis`, не `_archive`).

4. Извлечь `@twig` из макета:
   ```bash
   TWIG_PATH=$(grep -oE '@twig [^ ]+\.html\.twig' site/screens/$SCREEN.html | head -1 | awk '{print $2}')
   test -n "$TWIG_PATH" || { echo "🛑 STOP: нет мета-комментария @twig в макете"; exit 1; }
   echo "Target: $TWIG_PATH"
   ```

5. Извлечь `@prefix` (или взять имя файла):
   ```bash
   PREFIX=$(grep -oE '@prefix [a-z0-9-]+' site/screens/$SCREEN.html | head -1 | awk '{print $2}')
   [ -z "$PREFIX" ] && PREFIX=$SCREEN
   echo "Page prefix: $PREFIX-"
   ```

6. Проверить наличие линтера классов:
   ```bash
   test -f site/tools/check-ui-kit-classes.mjs || { echo "🛑 STOP: нет check-ui-kit-classes.mjs"; exit 1; }
   ```

---

## Phase 1 — Parse

Разобрать `screens/<name>.html` на три части:

1. **`<style>...</style>`** — page-specific CSS. Сохранить как `PAGE_CSS`.
2. **`<body>...</body>`** — разметка. Сохранить как `BODY_HTML`.
3. **`<script>...</script>`** — если есть, сохранить как `PAGE_JS` (обычно нет; если есть — в отчёт как «требует ручного переноса в Stimulus»).

Также извлечь `<link>` шрифтов из `<head>` — не переносить (шрифты уже в UI Kit), зафиксировать в отчёте.

---

## Phase 2 — Validate классы (критично, STOP при чужом)

Собрать все CSS-классы из `BODY_HTML`:

```bash
grep -oE 'class="[^"]+"' /tmp/body.html \
  | sed 's/class="//;s/"//' \
  | tr ' ' '\n' \
  | grep -v '^$' \
  | sort -u > /tmp/used-classes.txt
```

Категории:

| Категория | Признак | Действие |
|---|---|---|
| **UI Kit** | Найден в `ui-kit/components/*.css` или `ui-kit/patterns/*.css` | OK |
| **Page-specific** | Начинается с `<PREFIX>-` (например `login-brand`) | OK |
| **Utility** | Начинается с `u-` | OK |
| **State mock** | `is-hover`, `is-active`, `is-focus`, `is-on`, `is-error`, `is-selected`, `is-checked` | OK |
| **Чужой** | Ни одна из категорий выше | 🛑 STOP |

Проверка через существующий линтер:

```bash
cd site
node tools/check-ui-kit-classes.mjs --file ../screens/$SCREEN.html --prefix $PREFIX 2>&1 | tee /tmp/validate.log
```

Если линтер не поддерживает флаги — сверить `/tmp/used-classes.txt` со списком UI Kit классов вручную, отфильтровать page-prefix, utility и state-mocks; оставшиеся — кандидаты на STOP.

**При обнаружении чужого класса:**

```
🛑 STOP. Класс "<class>" не найден:
- не в UI Kit (ui-kit/components, ui-kit/patterns)
- не с page-префиксом "<PREFIX>-"
- не utility (u-*), не state-mock (is-*)

Варианты:
1. Опечатка → исправить в макете.
2. Забыт префикс → переименовать в <PREFIX>-<class>.
3. Новый UI Kit компонент → добавить в UI Kit сначала (задача дизайнеру).

Не генерирую битый Twig. Жду решения.
```

Только когда все классы валидны — продолжать.

---

## Phase 3 — Extract CSS

```bash
mkdir -p site/assets/styles/pages
```

Создать `site/assets/styles/pages/<name>.css`:

```css
/*
 * Page-specific styles: <name>
 * Generated from screens/<name>.html by screen-intake.
 * Only <PREFIX>-* classes. UI Kit classes live in ui-kit/.
 */

<PAGE_CSS содержимое>
```

**Валидация CSS:**
- Селекторы — только `.<PREFIX>-*`, `@media`, page-scoped. Если `<style>` переопределяет UI Kit класс (`.btn { ... }`) — 🛑 STOP, «page CSS переопределяет UI Kit класс, запрещено».
- Hex допустим только как page-specific токены в начале (`--brand-*`). Точечный hex в правилах — предупреждение в отчёт, не STOP.

---

## Phase 4 — Vite entry

Добавить entry `<name>` в `vite.config.js`:

```js
// В rollupOptions.input добавить:
<name>: './assets/styles/pages/<name>.css',
```

Правка через `str_replace` — вставить строку в объект `input`, сохранив формат существующих entries. Не менять другие entries.

Проверить, что entry ещё нет:
```bash
grep -q "^\s*$SCREEN:" site/vite.config.js && echo "🛑 entry уже существует, проверить"
```

---

## Phase 5 — Generate Twig

Из `BODY_HTML` сгенерировать Twig.

**Правила:**

1. **Extends + block.** Наследует базовый layout (из существующих security-шаблонов, обычно `{% extends 'security/base.html.twig' %}`), контент в `{% block body %}`.

2. **Подключение page CSS.** В `{% block stylesheets %}`:
   ```twig
   {{ parent() }}
   {{ vite_entry_link_tags('<name>') }}
   ```

3. **Плейсхолдеры дизайнера → TODO.** Интерактив `{{ pwInputType }}`, `sc-if`, `onClick="{{ toggle }}"` — не выдумывать. Заменять на HTML + TODO:

   ```twig
   {# TODO(symfony): csrf_token('authenticate') #}
   <input type="hidden" name="_csrf_token" value="">

   {# TODO(symfony): last_username из контроллера #}
   <input class="input" name="_username" value="">

   {# TODO(stimulus): password-toggle для кнопки-глаза #}
   <div class="password-field">
     <input class="input" type="password" name="_password">
     <button type="button" class="password-toggle" aria-label="Показать пароль">
       <!-- иконка -->
     </button>
   </div>

   {# TODO(symfony): error.messageKey|trans #}
   {# <div class="alert alert--danger">...</div> #}
   ```

4. **Статичный контент** (brand-панель, тексты, ссылки) — как есть. Ссылки-заглушки (`href="#"`) → `{# TODO(symfony): path('...') #}`.

5. **Никаких `<style>` внутри Twig** — весь CSS в page-файле.

**Если целевой Twig существует:**

```bash
if [ -f "site/$TWIG_PATH" ]; then
  OUT="site/$TWIG_PATH.new"
  echo "⚠️ $TWIG_PATH существует → генерирую $OUT для ручного слияния"
else
  OUT="site/$TWIG_PATH"
fi
```

Записать Twig в `$OUT`.

---

## Phase 6 — Build + verify

```bash
cd site
npm run build
```

- Exit 0.
- Entry `<name>` в `public/build/manifest.json`.
- `app-*.css` не сломан.

```bash
node tools/check-ui-kit-classes.mjs
# page CSS не добавляет нарушений
```

---

## Phase 7 — Отчёт в матрицу

Обновить `site/screens/README.md`:

```markdown
| Screen | Макет | Twig | CSS | Entry | Symfony TODO | Статус |
|---|---|---|---|---|---|---|
| login | screens/login.html | templates/security/login.html.twig.new | assets/styles/pages/login.css | ✅ | csrf, last_username, error, app_login, register path | 🟡 ждёт привязки |
```

Статусы: 🟡 верстка сгенерирована, ждёт Symfony; 🟢 полностью портирована.

---

## Phase 8 — PR

```bash
git checkout -b chore/screen-intake-<name>

git add site/assets/styles/pages/<name>.css
git add site/vite.config.js
git add "site/$TWIG_PATH"*
git add site/screens/README.md

git commit -m "chore(screen): intake <name> — verstka to Twig + CSS

Generated from screens/<name>.html:
- assets/styles/pages/<name>.css (page-specific)
- vite entry '<name>'
- <TWIG_PATH>[.new] (verstka only, Symfony bindings = TODO)

All classes validated against UI Kit v2.3.
Symfony bindings (csrf, routes, error, last_username) — manual follow-up."

git push -u origin chore/screen-intake-<name>

gh pr create --draft \
  --title "chore(screen): intake <name>" \
  --body "Верстка экрана <name> из screens/ в Twig + page CSS.

## Сгенерировано
- assets/styles/pages/<name>.css
- vite entry '<name>'
- <TWIG_PATH>[.new]

## Ручная доводка (Symfony) — TODO в шаблоне
- csrf_token, path() роуты, error / last_username / app.user
- Stimulus password-toggle (если есть)

## Verified
- [x] Все классы валидны против UI Kit v2.3
- [x] npm run build clean, entry в manifest
- [x] check-ui-kit-classes 0 нарушений"
```

---

## Self-review

- [ ] `@twig` путь извлечён из макета
- [ ] Все классы валидны (UI Kit / page-prefix / utility / state-mock), чужих нет
- [ ] `assets/styles/pages/<name>.css` создан, только `<PREFIX>-*` селекторы
- [ ] Page CSS не переопределяет UI Kit классы
- [ ] Vite entry `<name>` добавлен, формат не сломан
- [ ] Twig сгенерирован, `<style>` внутри нет
- [ ] Symfony-биндинги = `{# TODO #}`, не выдуманы
- [ ] Интерактив = TODO, Stimulus не выдуман
- [ ] Существующий Twig → `.new`, не перезаписан
- [ ] `npm run build` green, entry в manifest
- [ ] `check-ui-kit-classes` 0 нарушений
- [ ] Матрица в `screens/README.md` обновлена
- [ ] Draft PR открыт

---

## Что НИКОГДА не делать

```
выдумывать csrf/path/error биндинги               — только TODO
писать Stimulus-контроллер                        — только TODO
переопределять UI Kit класс в page CSS            — STOP
генерить Twig при чужом классе                    — STOP
перезаписывать существующий Twig                  — генерить .new
править security/base.html.twig                   — ручная задача
менять другие Vite entries                        — только добавить свой
мигрировать легаси Tabler-страницы                — другая задача
мерджить PR                                       — только Владелец
```

---

## Closing

🛑 STOP. Draft PR открыт. Ручная доводка Владельцем:

1. Привязать Symfony-биндинги по TODO.
2. Если есть интерактив — Stimulus-контроллер.
3. Если Twig был `.new` — слить с существующим, сохранив логику.
4. Smoke: рендер + функциональность.
5. Убрать TODO, статус в матрице → 🟢.
6. Merge.
