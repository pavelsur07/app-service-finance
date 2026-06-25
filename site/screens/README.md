# screens/ — HTML-макеты от дизайнера

Папка содержит HTML-макеты страниц, присланные дизайнером. Это **спецификация**, а не код. Используется как baseline при реализации Twig + React.

См. также:
- `CLAUDE.frontend.md` — общие правила фронтенда.
- `screen-intake.md` — задача для Claude Code на анализ каждого нового макета.
- `ui-kit/` — дизайн-система, классы которой используются в макетах.

---

## Структура

```
screens/
├── _analysis/                ← отчёты screen-intake task (Claude Code)
│   └── <name>.md
├── _archive/                 ← исторические версии макетов
│   └── <name>-YYYY-MM.html
├── <name>.html               ← текущая версия макета
└── README.md                 ← этот файл
```

---

## Правила для макетов

### Frontmatter

Каждый `<name>.html` начинается с HTML-комментария-фронтматтера:

```html
<!--
uiKit: 1.2.0
designedAt: 2026-06-25
source: <ссылка на Figma или чат с дизайнером, если есть>
designer: <имя или ник>
-->
<!DOCTYPE html>
<html lang="ru">
...
```

`uiKit` — версия UI Kit, под которую макет нарисован. Если макет рисовался под `1.2`, а в проекте уже `1.3` с переименованным классом, screen-intake task это поймает и потребует решения.

### Подключение стилей UI Kit

Макет должен подключать те же CSS-файлы, что и продакшен:

```html
<link rel="stylesheet" href="../ui-kit/tokens/index.css">
<link rel="stylesheet" href="../ui-kit/components/all.css">
<link rel="stylesheet" href="../ui-kit/patterns/all.css">
```

Тогда изменение DS автоматически отражается во всех макетах — дрейф между дизайном и продом исключён по построению.

### Версионирование

- Текущая версия макета — `screens/<name>.html`.
- При получении новой версии того же экрана: текущий файл переносится в `_archive/<name>-YYYY-MM.html`, новый ложится на его место под прежним именем.
- В коммите явное сообщение: `chore(screens): bump <name>.html (v2)`.

Это даёт историю изменений макета без потери предыдущих версий и без зависимости от чужих сервисов.

---

## Workflow при поступлении нового макета

```
1. Дизайнер прислал HTML
       │
       ▼
2. Кладём в screens/<name>.html (или в _archive/, если это обновление)
       │
       ▼
3. Запускаем screen-intake task для этого файла
   (см. screen-intake.md, выполняет Claude Code в автономном режиме)
       │
       ▼
4. Получаем отчёт screens/_analysis/<name>.md
       │
       ▼
5. Владелец отвечает на «Open questions» в отчёте:
   - Решает с дизайнером новые компоненты UI Kit
   - Согласовывает API-контракт с бэкендом
   - Утверждает Twig/React split
       │
       ▼
6. Создаются отдельные задачи:
   - Обновление UI Kit (если есть новые компоненты)
   - Реализация модуля (по правилам CLAUDE.frontend.md)
```

**Никакая реализация не начинается до прохождения всех шести шагов.**

---

## Матрица реализации

| Screen | UI Kit ver. | Analysis | Twig | React module | Vite entry | Status |
|---|---|---|---|---|---|---|
| `login.html` | 1.2 | — | `templates/security/login.html.twig` | — | — | TODO: analyse |
| `dashboard.html` | — | — | — | — | — | not received |
| `reconciliation.html` | — | — | — | — | — | not received |
| `marketplace-analytics.html` | — | — | — | — | — | not received |
| `ingestion-verification.html` | — | — | — | — | — | not received |
| `marketplace-ads.html` | — | — | — | — | — | not received |

Колонки:

- **UI Kit ver.** — версия UI Kit из frontmatter макета.
- **Analysis** — ссылка на отчёт screen-intake, например `_analysis/reconciliation.md`. Прочерк, если ещё не прогоняли intake.
- **Twig** — путь к Twig-шаблону, реализующему статичные блоки страницы.
- **React module** — путь к модулю с React-островами, например `assets/react/modules/reconciliation/`.
- **Vite entry** — имя entry в `vite.config.ts`, например `reconciliation`.
- **Status** — одно из:
    - `not received` — макет ещё не прислан дизайнером.
    - `TODO: analyse` — макет есть, screen-intake ещё не запускали.
    - `TODO: review` — intake прогнали, ждём решений по Open questions.
    - `in progress` — задача на реализацию активна.
    - `done` — screen реализован в проде.
    - `deprecated` — screen больше не используется (но файл оставлен в `_archive/`).

Матрица обновляется **вручную** в каждом PR, который меняет статус (analyse → review → in progress → done). При done — обязательно проставить пути к Twig/Module/Entry.

---

## `_analysis/` — отчёты screen-intake

Файлы вида `<name>.md`. Структура и формат — см. `screen-intake.md`, раздел «Формат отчёта».

`_analysis/` не удаляется при реализации screen — отчёт остаётся как baseline на момент анализа, чтобы потом можно было свериться «а почему мы сделали именно так».

---

## `_archive/` — предыдущие версии макетов

Файлы вида `<name>-YYYY-MM.html`. Хронологический порядок (последний по дате — последняя предыдущая версия).

При работе с текущей версией макета `_archive/` обычно не нужен. Используется только в случаях:

- Дизайнер просит вернуть как было — открываем последний из `_archive/`, кладём обратно как `<name>.html`.
- Разбираемся, что менялось между двумя версиями — `diff` между `_archive/<name>-YYYY-MM.html` и `<name>.html`.

---

## Что НЕ делать в `screens/`

- **Не редактировать макеты.** Они от дизайнера, это спецификация. Если что-то не так — возвращать дизайнеру, не править «по дороге».
- **Не использовать макеты в продакшене.** `screens/` не подключается к Symfony, не отдаётся пользователям, не индексируется.
- **Не импортировать из `screens/`** в `assets/` или `templates/`. Это spec, а не код.
- **Не складывать сюда экспорты Figma в виде PNG/SVG.** Только HTML. Картинки — в `ui-kit/icons/` или в task-папке.
- **Не запускать screen-intake task на свой страх и риск** для нескольких файлов сразу. Один screen = один запуск intake.
