# Screen Intake Task — анализ макета от дизайнера

> **Эта задача запускается каждый раз, когда дизайнер прислал новый или обновлённый HTML-макет страницы.**
> Задача — только анализ. Никакого кода. Цель — подготовить план реализации, который потом пойдёт в обычный фронт-workflow.

---

## Когда запускать

Эту задачу Claude Code выполняет, когда выполнено любое из условий:

- В `screens/` появился новый файл (например, `screens/reconciliation.html`).
- Существующий screen обновлён (изменился git-diff в `screens/<name>.html`).
- Владелец явно сказал: «Запусти screen intake для `screens/<name>.html`».

Без явного триггера задача **не запускается** — никаких «заодно проанализирую и второй screen».

---

## Что эта задача делает и чего НЕ делает

**Делает:**

- Парсит HTML-макет.
- Сверяет используемые классы с актуальным состоянием `ui-kit/`.
- Размечает страницу на статичные блоки (Twig) и интерактивные острова (React).
- Формирует список новых компонентов, которые нужно добавить в `ui-kit/` или в модуль.
- Пишет структурированный отчёт в `screens/_analysis/<name>.md`.

**НЕ делает:**

- Не пишет React-компоненты.
- Не правит Twig-шаблоны.
- Не добавляет ничего в `ui-kit/` (только пишет «надо добавить вот это»).
- Не меняет `assets/`, `templates/`, `src/`, `vite.config.ts`, `package.json`.
- Не создаёт Vite entrypoints.
- Не запускает `npm install`.

Любое отклонение от этого списка — 🛑 **STOP, спросить владельца**.

---

## Входы

| Что | Где |
|---|---|
| Макет страницы | `screens/<name>.html` (обязательно) |
| Текущий UI Kit | `ui-kit/components/*.html`, `ui-kit/components/*.css`, `ui-kit/storybook.html` |
| Токены DS | `ui-kit/tokens/*.css` |
| Журнал изменений UI Kit | `ui-kit/CHANGELOG.md` |
| Решения дизайна | `ui-kit/decisions.md` |
| Существующие React-обёртки | `assets/react/ui-kit/**` |
| Существующие модули | `assets/react/modules/**` |
| Существующие shared-инпуты | `assets/react/shared/**` |
| API-схема (если есть) | `assets/api/schema.d.ts` |

Если какого-то из обязательных входов нет → 🛑 STOP, описать чего не хватает.

---

## Выход

**Один файл:** `screens/_analysis/<name>.md` строго по шаблону из раздела «Формат отчёта» ниже.

Дополнительные побочные эффекты:

- Если в `screens/<name>.html` нет frontmatter с версией UI Kit — Claude Code предлагает (в отчёте, не правит сам!) добавить:
  ```
  ---
  uiKit: <текущая версия из ui-kit/CHANGELOG.md>
  designedAt: <YYYY-MM-DD>
  source: <ссылка на Figma/чат с дизайнером, если известно>
  ---
  ```

---

## Фазы

### Phase 0 — Валидация входа

1. Проверить, что `screens/<name>.html` существует и читается.
2. Открыть HTML, убедиться что он парсится (валидный DOM, не битый).
3. Проверить, что нет `<script>`-блоков с реальным JS (макет дизайнера = только разметка + стили). Если есть — отметить в отчёте «требует прояснения, что именно дизайнер хотел показать скриптом».
4. Проверить, что нет `<link>` или `<img>` с битыми ссылками. Битые — в отчёт в раздел «Вопросы дизайнеру».
5. Прочитать `ui-kit/CHANGELOG.md`, зафиксировать текущую версию UI Kit (например, `1.2.0`).
6. Прочитать `ui-kit/decisions.md`, зафиксировать в памяти последние решения (они могут влиять на трактовку макета).

**STOP-условие:** если HTML не парсится, нет UI Kit, нет ключевых файлов — 🛑 STOP, описать ситуацию.

---

### Phase 1 — Инвентаризация макета

Прочитать HTML и собрать **исчерпывающие** списки.

**1.1. Список CSS-классов, использованных в макете.**

Извлечь все `class="..."` (включая множественные), дедуплицировать, отсортировать. В отчёт идёт раздел «Использованные классы» — простой список.

**1.2. Список визуальных секций.**

Пройти по DOM сверху вниз, выделить логические секции. Для каждой:
- Краткое название («Page header», «KPI row», «Transactions table», «Sidebar filters»).
- Координаты в DOM (XPath или CSS-селектор до корня секции).
- Грубое описание содержимого («4 KPI-карточки в ряд», «таблица с пагинацией снизу», «модалка с формой загрузки»).

**1.3. Список повторяющихся блоков.**

Любая структура, которая повторяется ≥2 раз внутри одного screen, — кандидат на компонент. Записать:
- Где встречается (несколько селекторов).
- Сколько раз.
- Описание.

Пример:
```
Повтор: .kpi (4 раза в секции "KPI row")
Повтор: .acc-card (5 раз в секции "Bank accounts")
Повтор: tr.transaction-row (динамический, в таблице — будет рендериться по данным)
```

**1.4. Список интерактивных элементов.**

Любой элемент, поведение которого изменяется по действию пользователя:
- `<button>`, `<a>` с действием (не навигация).
- Поля ввода, чекбоксы, селекты.
- Tabs, accordion, modal triggers.
- Toggles, switches.
- Drag-and-drop зоны (по визуальным признакам — например, `<div class="dropzone">`).

Для каждого: селектор + предполагаемое действие («открывает модалку», «меняет фильтр», «сортирует таблицу»).

**1.5. Список «данных с сервера».**

Любой контент, который очевидно динамический:
- Числа в KPI (это данные).
- Строки таблицы (это данные).
- Имена пользователей, названия компаний, суммы (это данные).
- Статусы, бэйджи (это данные, статус-вариант определяется значением).

Записать: «что-то является данными → значит, нужен API-эндпоинт».

---

### Phase 2 — Сверка с UI Kit

Для **каждого** класса из списка 1.1 определить статус:

| Статус | Условие | Что делать |
|---|---|---|
| ✅ **В UI Kit** | Класс определён в `ui-kit/components/*.css` или `ui-kit/storybook.html` | Использовать как есть |
| ⚠️ **В UI Kit, но новый вариант** | Базовый класс есть (`.btn`), но модификатор новый (`.btn--xxl`) | В отчёт: «обсудить с дизайнером — это новый вариант или ошибка» |
| ❌ **НЕ в UI Kit** | Класса нет нигде в `ui-kit/` | В отчёт: «новый класс, требует решения» |
| 🔄 **Похож на существующий** | Класса нет, но визуально совпадает с уже существующим (например, `.financial-status` похож на `.status`) | В отчёт: «переименовать в существующий класс» |

Для каждого ❌ и 🔄 — предложить один из трёх вариантов:
1. **Добавить в UI Kit** — если это переиспользуемый компонент. Указать, в `components/` (примитив) или `patterns/` (композит).
2. **Использовать существующий класс** — если это дубликат.
3. **Сделать локальным стилем модуля** — если это разовая композиция, не нужная другим страницам. В этом случае CSS Module внутри модуля.

Для **каждой** React-обёртки в `assets/react/ui-kit/` проверить, нужна ли она для классов из этого screen. Если screen использует `.btn`, а `Button.tsx` уже есть — отметить «реюз». Если использует новый класс `.toolbar`, обёртки нет — отметить «нужна новая обёртка».

---

### Phase 3 — Twig / React split

Каждую визуальную секцию из 1.2 классифицировать:

| Тип | Признаки | Куда |
|---|---|---|
| 🟦 **Twig (статика)** | Нет интерактива, нет данных с API, контент известен на момент рендера сервером | `templates/<module>/<screen>.html.twig` |
| 🟧 **React island** | Есть интерактив (фильтры, сортировка, модалки) ИЛИ данные приходят с API после загрузки страницы | `assets/react/modules/<module>/features/<feature>/` |
| 🟨 **Twig с лёгким Stimulus** | Минимальный интерактив (показать/скрыть, копировать в буфер) без данных с API | `assets/controllers/<name>_controller.js` (если Stimulus решено оставить) |

Правило по умолчанию: **если сомневаешься — Twig**. React-острова дороже в поддержке.

Для **каждого React-острова**:
- Имя острова (kebab-case): `reconciliation-filters`, `reconciliation-kpi`, `reconciliation-transactions`.
- Какие компоненты UI Kit использует.
- Какие данные с сервера ему нужны (со ссылкой на список 1.5).
- Какой Vite entrypoint и Twig mount point ему потребуется.

Результат фазы — таблица:

```
| Секция | Тип | Модуль / контроллер | Острова |
|---|---|---|---|
| Page header | Twig | templates/reconciliation/show.html.twig | — |
| KPI row | React | modules/reconciliation/features/kpi-summary | reconciliation-kpi |
| Filters | React | modules/reconciliation/features/filters | reconciliation-filters |
| Table | React | modules/reconciliation/features/transactions | reconciliation-transactions |
| Footer | Twig | templates/reconciliation/show.html.twig | — |
```

---

### Phase 4 — План извлечения компонентов

На основе фаз 1–3 составить **четыре списка**, каждый со ссылкой на файлы/классы:

**4.1. Новые компоненты в UI Kit (`ui-kit/components/`).**

Примитивы, которые надо добавить в DS. Для каждого:
- Имя класса (`.toolbar`).
- HTML-шаблон (черновик, из screen).
- Список вариантов и состояний.
- Обоснование: почему примитив, а не паттерн.

**4.2. Новые паттерны в UI Kit (`ui-kit/patterns/`).**

Композиты (KpiRow, FilterBar, EmptyState). Для каждого:
- Имя паттерна.
- Из каких примитивов состоит.
- Где ещё в проекте может пригодиться (если очевидно).

**4.3. Новые React-обёртки (`assets/react/ui-kit/`).**

Для каждого нового класса/компонента из UI Kit — нужна ли React-обёртка. Указать:
- Имя компонента (`Toolbar`).
- Сигнатура пропсов (черновик).
- Ссылка на HTML-референс в `ui-kit/components/<name>.html`.

**4.4. Новые feature-компоненты в модуле.**

Бизнес-блоки, которые живут только внутри модуля и не идут в DS. Для каждого:
- Путь (`modules/reconciliation/features/kpi-summary/KpiSummaryWidget.tsx`).
- Что делает.
- Какие компоненты UI Kit использует.

---

### Phase 5 — Сформировать отчёт и STOP

Записать всё в `screens/_analysis/<name>.md` строго по шаблону ниже.

В конце отчёта — раздел «Открытые вопросы»: всё, что осталось неясным и требует ответа от владельца или дизайнера. Без этих ответов — следующая задача (реализация) **не стартует**.

🛑 **STOP. Ждать ревью владельца.** Никакого кода, никаких изменений в `ui-kit/`, `assets/`, `templates/`.

---

## Формат отчёта (`screens/_analysis/<name>.md`)

```markdown
# Screen analysis: <name>

**Source:** `screens/<name>.html`
**UI Kit version:** <X.Y.Z из ui-kit/CHANGELOG.md>
**Analysed at:** <YYYY-MM-DD>
**Analyst:** Claude Code (autonomous, screen-intake task)

---

## 1. Sections inventory

| # | Section | Selector | Description |
|---|---|---|---|
| 1 | Page header | `body > header.page-header` | Title + breadcrumb + action button |
| 2 | KPI row | `body > main > section.kpi-row` | 4 cards in a row |
| ... |

## 2. CSS classes usage

### Reused from UI Kit (✅)
- `.btn`, `.btn-primary`, `.btn-secondary`, `.btn-md`
- `.card`, `.card-header`, `.card-body`
- `.kpi`, `.kpi-label`, `.kpi-value`
- ...

### New variants of existing (⚠️)
- `.btn--xxl` — нового размера в UI Kit нет, нужно решение

### Not in UI Kit (❌)
- `.toolbar` — новый компонент, предлагается добавить
- `.dropzone` — новый компонент, предлагается добавить

### Look-alikes to existing (🔄)
- `.financial-status` похож на `.status` — предлагается переименовать в `.status`

## 3. Twig / React split

| Section | Type | Module / template | Island name |
|---|---|---|---|
| Page header | Twig | `templates/reconciliation/show.html.twig` | — |
| KPI row | React | `modules/reconciliation/features/kpi-summary` | `reconciliation-kpi` |
| Filters | React | `modules/reconciliation/features/filters` | `reconciliation-filters` |
| ... |

## 4. Component extraction plan

### 4.1. New UI Kit primitives (to add to `ui-kit/components/`)
- **Toolbar** — `.toolbar`, variants: `default`. Reference HTML draft attached.
- **Dropzone** — `.dropzone`, states: `idle | dragover | error`.

### 4.2. New UI Kit patterns (to add to `ui-kit/patterns/`)
- **KpiRow** — composition of 2–6 `.kpi` cards with responsive collapse.
- **FilterBar** — composition of `.chip--filter` + dropdowns + search.

### 4.3. New React wrappers (to add to `assets/react/ui-kit/`)
- **Toolbar** — wraps `.toolbar` + slots.
- **Dropzone** — wraps `.dropzone` + onDrop/onError handlers.

### 4.4. New feature components (in module)
- `modules/reconciliation/features/kpi-summary/KpiSummaryWidget.tsx`
- `modules/reconciliation/features/kpi-summary/KpiSummaryView.tsx`
- `modules/reconciliation/features/kpi-summary/useKpiSummary.ts`
- `modules/reconciliation/features/filters/...`
- `modules/reconciliation/features/transactions/...`

## 5. API requirements

| Endpoint | Method | Params | Returns | Status |
|---|---|---|---|---|
| `/api/reconciliation/kpi` | GET | `period` | `{ matched, mismatched, missing }` | needs confirmation from backend |
| `/api/reconciliation/transactions` | GET | `period, status, page, perPage` | `{ data, meta }` | needs confirmation from backend |
| ... |

## 6. Vite entrypoints + Twig mount points

| Entry | File | Twig mount point |
|---|---|---|
| `reconciliation` | `assets/react/entrypoints/reconciliation.tsx` | `<div data-island="reconciliation-kpi" data-props="..."></div>` (× 3) |

## 7. Risks and unknowns

- New UI Kit components: 2 (Toolbar, Dropzone) → требует апрува дизайнером и bump UI Kit (minor).
- New API endpoints: 2 → требует подтверждения бэкенд-разработчика.
- `.btn--xxl`: непонятно, это намеренный новый размер или ошибка дизайнера → вопрос дизайнеру.

## 8. Open questions (for Owner / Designer / Backend)

1. **[Дизайнер]** `.btn--xxl` — это новый размер кнопки или опечатка?
2. **[Дизайнер]** `.financial-status` — переименовать в `.status` или это отдельный компонент?
3. **[Бэкенд]** Подтвердить контракт `/api/reconciliation/kpi` и `/api/reconciliation/transactions`.
4. **[Владелец]** Утвердить добавление `Toolbar` и `Dropzone` в UI Kit (требует bump 1.2 → 1.3).

## 9. Next steps (предлагаются, не выполняются)

1. Получить ответы на «Open questions».
2. Обновить `ui-kit/`: добавить Toolbar, Dropzone (отдельная задача, по правилам CLAUDE.frontend.md).
3. После обновления UI Kit — создать задачу на реализацию модуля reconciliation (отдельная задача, по правилам CLAUDE.frontend.md).

---

🛑 **STOP. Анализ завершён. Ждать ревью владельца перед любой реализацией.**
```

---

## Self-review (выполнять перед STOP)

- [ ] Каждая секция HTML попала в раздел «Sections inventory»
- [ ] Каждый CSS-класс получил статус (✅/⚠️/❌/🔄)
- [ ] Каждая секция классифицирована как Twig / React / Twig+Stimulus
- [ ] Для каждого React-острова указаны: имя, модуль, компоненты UI Kit, требуемые данные
- [ ] Все «❌ Not in UI Kit» имеют предложение (добавить в DS / реюзать существующий / локальный стиль)
- [ ] Все динамические данные сопоставлены с API-эндпоинтами (даже если эндпоинт ещё не существует)
- [ ] В разделе «Open questions» есть конкретные вопросы с адресатом ([Дизайнер] / [Бэкенд] / [Владелец])
- [ ] Файл `screens/_analysis/<name>.md` создан
- [ ] Никакие другие файлы не изменены (никаких правок в `ui-kit/`, `assets/`, `templates/`, `src/`)
- [ ] Никаких `npm install`, никаких изменений `package.json` / `vite.config.ts`

Если хоть один пункт не выполнен — анализ **не завершён**, доработать или 🛑 STOP с объяснением.

---

## Что НИКОГДА не делать в этой задаче

```
писать React-компоненты                           — это другая задача
править ui-kit/                                   — это другая задача
править Twig-шаблоны                              — это другая задача
менять vite.config.ts / package.json              — это другая задача
запускать npm install                             — это другая задача
делать коммит с кодом                             — только коммит отчёта
анализировать несколько screen за раз             — один screen = один запуск
домысливать намерения дизайнера без вопроса       — в Open questions
утверждать новые компоненты UI Kit самостоятельно — только предложение в отчёте
```

---

## Закрытие задачи

1. Файл `screens/_analysis/<name>.md` создан и заполнен по шаблону.
2. Self-review пройден.
3. Сделан коммит: `docs(screens): analyse <name>.html`.
4. 🛑 **STOP. Ждать решений по «Open questions» от владельца.**

После апрува владельца появятся **отдельные** задачи:
- Обновление `ui-kit/` (если есть новые компоненты).
- Реализация модуля (Twig + React-острова) — по правилам `CLAUDE.frontend.md`.

Эти задачи **не входят** в screen-intake. Они стартуют как новые автономные задачи со своими планами и этапами.
