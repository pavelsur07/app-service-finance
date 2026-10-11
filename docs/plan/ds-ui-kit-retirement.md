# Вывод старого UI Kit (`site/ui-kit/`) из эксплуатации — план

Stage 6 поручения о дизайн-системе «Ваш Финдир». Дата: 2026-10-11, база — `master`
после деплоя `5ae68d97`. **Удаление папки и гейтов — только после отдельного одобрения
Владельца** (`AGENTS.md` §3.3). Документ ничего не меняет в коде.

## 1. Главный вывод

`site/ui-kit/` — это **не только справочник**. 56 CSS-файлов из него — живой
исходник стилей прода: их тянет `@import` в сборку Vite. Удалить папку целиком
нельзя, пока на эти стили опираются страницы. План — **перенести живой CSS в
`site/assets/`, а удалить только справочный материал и гейты**. Визуально ничего не
меняется: те же файлы, другой путь.

## 2. Карта зависимостей

### 2.1 Что в папке (8,6 МБ)

| Часть | Объём | Статус |
|---|---|---|
| `base.css` (1), `tokens/*.css` (8), `components/*.css` (33, включая `states.css`), `patterns/*.css` (14) | 56 файлов CSS | **живое** — импортируется в сборку |
| `components/*.html`, `patterns/*.html` | 56 HTML | справочная разметка; связаны с React-обёрткой через `@react`/`@uiKit` |
| `storybook.html` | 336 КБ | справочник |
| `decisions.md`, `design-audit.md`, `uikit-update.md`, `CHANGELOG.md`, `README.md` | ~60 КБ | документация старого кита |
| `_audit/` (скриншоты до/после), `_incoming/`, `promts/` | 7,7 МБ | архив, нигде не используется |

### 2.2 Кто использует живой CSS

| Потребитель | Как | Страницы |
|---|---|---|
| `assets/styles/app.css` | `@import ui-kit/{base,tokens/index,components/all,patterns/all,components/states}.css` | новый слой `_layout/app.html.twig` (28 страниц), админка `admin/base.html.twig` (10), авторизация `security/base_auth.html.twig` |
| `assets/styles/pages/marketplace-coverage.css` | `@import ui-kit/tokens/index.css`, `ui-kit/components/coverage.css` | легаси-страница «Покрытие» `marketplace/index.html.twig` |
| `.github/workflows/deploy.yml:70` | фильтр путей `site/ui-kit/**` → сборка фронта | CI/деплой |

### 2.3 Кто ссылается на справочную часть

| Где | Что |
|---|---|
| `package.json` | скрипты `check:ui-kit`, `check:uikit-react-mapping` |
| `tools/check-ui-kit-classes.mjs` | гейт «класс есть в ui-kit»: **9081 нарушение**, красный до задачи, в CI не входит |
| `tools/check-uikit-react-mapping.mjs` | гейт связи `@uiKit` ↔ `@react`: **красный до задачи** (`TreePicker`), в CI не входит |
| `Makefile:171–175` | цели `check-ui-kit`, `check-uikit-react-mapping` |
| `assets/react/ui-kit/Button/Button.tsx`, `index.ts` | JSDoc `@uiKit ui-kit/components/button.html`, комментарии |
| `ui-kit/components/*.html` (10 шт.) | обратные ссылки `<!-- @react … -->` |
| Правила: `AGENTS.md` (таблица документов), `CLAUDE.md` (§ Design System), `CLAUDE.frontend.md` (источники правды, UI Kit, гейты), `docs/workflow/stage-report-frontend.md` (команды, раздел UI Kit), `docs/workflow/templates.md` (фронтовый Stage), `screen-intake.md`, `site/screens/README.md` | описывают кит как источник истины и гейты |
| Исторические `docs/tasks/*`, `docs/migration/uikit-update-*.md` | упоминания в прошлом | **не трогаем** — это история |

Не зависят от папки: `assets/react/ui-kit/` (код обёрток; ESLint-зоны указывают на
него, а не на `site/ui-kit/`), легаси-слой (`design-tokens.css`, `vf-custom-classes.css`).

## 3. Что предлагается

### Этап 1 — перенос живого CSS (без изменения визуала)
- `git mv site/ui-kit/{base.css,tokens,components/*.css,patterns/*.css}` →
  `site/assets/styles/kit/` (структура каталогов сохраняется).
- Поправить `@import` в `assets/styles/app.css` и `pages/marketplace-coverage.css`.
- `deploy.yml`: строка `site/ui-kit/**` больше не нужна — `site/assets/**` уже в фильтре.
- Проверка: `npm run build` — хеши итоговых CSS-бандлов `app`, `marketplace_coverage`
  **совпадают** с текущими (одинаковое содержимое → одинаковый бандл); скриншоты страниц
  нового слоя, админки, авторизации и «Покрытия» до/после.

### Этап 2 — удаление справочного материала и гейтов (**нужно одобрение**)
- Удалить: `storybook.html`, `components/*.html`, `patterns/*.html`, `decisions.md`,
  `design-audit.md`, `uikit-update.md`, `CHANGELOG.md`, `README.md`, `_audit/`,
  `_incoming/`, `promts/` — то есть папку `site/ui-kit/` целиком после этапа 1.
  История остаётся в git.
- Удалить гейты: `tools/check-ui-kit-classes.mjs`, `tools/check-uikit-react-mapping.mjs`,
  скрипты в `package.json`, цели в `Makefile`.
- `Button.tsx`, `index.ts`: JSDoc `@uiKit` → ссылка на CSS в `assets/styles/kit/`.

### Этап 3 — правила и документация
- `CLAUDE.frontend.md`: источник правды для визуала — листы DS
  `docs/design-system/sheets/` для обоих слоёв; реализация нового слоя — CSS в
  `assets/styles/kit/`, легаси — `design-tokens.css` + `vf-custom-classes.css`;
  убрать требования про `CHANGELOG`, `storybook.html`, `@uiKit`/`@react`; список
  гейтов — без двух удалённых.
- `CLAUDE.md` § Design System, `AGENTS.md` (таблица документов),
  `docs/workflow/stage-report-frontend.md` (команды и раздел «UI Kit»),
  `docs/workflow/templates.md`, `screen-intake.md`, `site/screens/README.md` — те же
  правки.
- `docs/design-system/README.md`: убрать «Удаление — Stage 6».

### Порядок PR
Один PR на этап; 1 → 2 → 3. Этапы 1 и 3 без одобрения не исполняются вместе с 2,
потому что смысл переноса — подготовка к удалению.

## 4. Решения Владельца

| # | Вопрос | Рекомендация |
|---|---|---|
| 1 | Удалить `site/ui-kit/` и два гейта | **Да**, после переноса живого CSS (этап 1) |
| 2 | Источник истины для нового слоя после удаления | Листы DS «Ваш Финдир» — для всего продукта; CSS нового слоя — реализация, а не эталон |
| 3 | Заменить `check:ui-kit` другим гейтом (например, «хардкод-цвета вне токенов») | Не сейчас: оба старых гейта и так не в CI; новый — отдельной задачей, если нужен |
| 4 | Перевод самого нового слоя на палитру DS | Вне этого плана — отдельная задача |

## 5. Риски
- **Визуальная регрессия нового слоя** при переносе — контроль совпадением хешей
  бандлов и скриншотами; при несовпадении — разбор до мержа.
- **Порядок каскада** в `app.css` (`states.css` последним) — сохраняется как есть.
- **Ссылки из документов** на удалённые файлы — этап 3 правит живые правила;
  исторические `docs/tasks/*` остаются как есть.
- Миграций и изменений API нет; откат — revert PR.

## 6. Проверки на каждом этапе
- `npm run lint`, `npm run build` (+ сравнение хешей бандлов на этапе 1).
- `make site-test` — на handoff (шаблоны не меняются, кроме комментариев).
- Smoke: страница нового слоя, админка, регистрация/вход, «Покрытие» маркетплейсов.
