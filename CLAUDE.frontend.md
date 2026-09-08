# CLAUDE.frontend.md — Frontend Rules

Правила фронтенда: Symfony + Twig + React + Vite с собственным UI Kit как
источником правды для визуала.

**Workflow — `AGENTS.md`, он же для фронта.** Иерархия `Work item → Stage →
Handoff → merge + deploy`, один вопрос Владельцу за задачу, классификация
Fast Path / Small / Large, STOP-список §3.4, политика ревью §7. Отдельного
фронтового workflow, апрува плана и остановки после каждого этапа нет.
Фронтовый self-review checklist — `docs/workflow/stage-report-frontend.md`.

## Текущее состояние против целевого

Документ описывает целевую архитектуру. Часть её ещё не существует. Ниже —
факт на 2026-09-08, проверенный по репозиторию. Не выдавать целевое за
существующее и не ссылаться в коде на то, чего нет.

| Целевое | Факт |
|---|---|
| `assets/react/modules/<module>/features/*` | нет; весь React в `assets/react/_legacy/`, 100 файлов |
| `assets/react/entrypoints/` | нет; entry указывают прямо на файлы `_legacy/` |
| `mountIsland`, `AppProviders`, `ErrorBoundary` | не реализованы |
| `templates/_macros/ui-kit.html.twig` | нет |
| `ui-kit/icons/` | нет |
| React-обёртки UI Kit | одна: `assets/react/ui-kit/Button/` |
| `assets/react/shared/` | один файл `lib/cn.ts` |

Не установлены и потому не применяются: TanStack Query, React Hook Form, Zod,
`clsx`, тест-раннер. Установка любой из них — отдельное одобрение Владельца
(`AGENTS.md` §3.3), а не решение внутри задачи.

## Гейты — что есть и чего нет

```bash
npm run lint                        # eslint, --max-warnings=0
npm run build                       # vite build
npm run check:ui-kit                # классы в коде существуют в ui-kit/
npm run check:uikit-react-mapping   # двусторонняя связь @uiKit ↔ @react
npm run api:types:check             # schema.d.ts синхронна с OpenAPI
```

**Скриптов `typecheck` и `test` в проекте нет, и тест-раннер не установлен.**
Это зафиксированное состояние, а не забытая настройка: типы проверяются только
тем, что `tsc` делает внутри `vite build`, а автотестов на фронте нет вовсе.
Не писать в отчётах, что они прогнаны, и не добавлять их в команду закрытия.
Пока их нет, обязательная проверка фронтового изменения — четыре команды выше
плюс ручной smoke затронутой страницы.

`tsconfig.json`: включён `strict`. Флагов `noUncheckedIndexedAccess`,
`exactOptionalPropertyTypes`, `noImplicitReturns`,
`noFallthroughCasesInSwitch` там нет — не ссылаться на них как на данность.

Node и npm живут только в контейнере, на хосте их нет.

## Источники правды

| Слой | Источник правды для | Где живёт |
|---|---|---|
| UI Kit | Визуальный язык: классы, токены, компоненты | `site/ui-kit/` |
| Behavior layer | Поведение браузера и a11y: `:disabled`, `:read-only`, `:autofill`, focus | `ui-kit/components/states.css`, владелец — код |
| Screens | Спецификация страниц от дизайнера | `site/screens/<name>.html` |
| Код | Реализация | `templates/`, `assets/`, `src/` |

- Код использует класс, которого нет в UI Kit — виноват код, ловит
  `check:ui-kit`.
- Screen использует класс, которого нет в UI Kit — вернуть дизайнеру или
  дополнить UI Kit до реализации.
- UI Kit противоречит сам себе — решение в `ui-kit/decisions.md` и запись в
  CHANGELOG.

Код никогда не источник правды. Исключение одно — behavior layer: `states.css`
принадлежит коду, там живёт поведение браузера, которого в макете дизайнера нет
и не будет. Новых классов он не вводит, только псевдоклассы на существующих.

## UI Kit

**Токены.** Raw-токены в `ui-kit/tokens/colors.css`, `typography.css`,
`spacing.css`, `radius.css`, `shadows.css`; semantic-токены в `semantic.css`.
В компонентах UI Kit и в feature-стилях использовать только semantic-токены.
Raw-токен напрямую в компоненте запрещён, хардкод цвета запрещён везде, кроме
`tokens/colors.css`. Это позволяет переименовать raw-токен без правки
компонентов и сделать тёмную тему переопределением одного файла.

**Классы.** В Twig, React и CSS Modules только классы из `ui-kit/`. Кастомные
`.my-button`, `.special-card` в модулях запрещены. Мелкий локальный оверрайд —
CSS Module с классом-обёрткой:

```css
/* KpiSummaryView.module.css */
.root { padding-top: var(--space-4); }
```

Внутри CSS Module нельзя переопределять `.btn`, `.card` и прочие классы UI Kit,
только обёртки. Понадобился один и тот же оверрайд в двух модулях — нужен новый
вариант компонента в UI Kit, а не вторая копия CSS.

**React-обёртки** живут в `assets/react/ui-kit/<Name>/<Name>.tsx`. Обёртка не
пишет свой CSS: она типизирует пропсы, нормализует классы, делает мелкую логику
вроде `disabled` при `loading`. Классы мерджатся хелпером `cn` из
`@/react/shared/lib/cn` (`clsx` не установлен). Обязателен JSDoc с `@uiKit
<путь>` и `@version <X.Y>`, а в HTML-файле UI Kit — обратный комментарий
`<!-- @react <ComponentName> -->`. Двустороннюю связь проверяет
`check:uikit-react-mapping`. Образец — `assets/react/ui-kit/Button/Button.tsx`.

**Версия UI Kit — только в `ui-kit/CHANGELOG.md`**, в других документах её не
дублировать. Любое изменение `ui-kit/*` сопровождается записью в CHANGELOG и
bump `@version` в затронутых обёртках. Semver: patch — косметика, minor — новые
компоненты, варианты и токены без поломки, major — переименование или удаление
классов, что требует отдельной задачи на миграцию потребителей и deprecation в
одном minor до удаления.

Изменение токенов, добавление, удаление или переименование класса в
`ui-kit/components/*` и `ui-kit/patterns/*`, добавление компонента в
`assets/react/ui-kit/` — HIGH-LOCAL: усиленные проверки и внимательный review,
без остановки. Новый компонент добавлять, когда существующий не подходит,
обновив в том же Stage `storybook.html`, `decisions.md`, обёртку и CHANGELOG.

## React

**Smart / Dumb.** Нетривиальная фича делится минимум на два файла. Widget —
smart: хук, состояние, обработчики, маппинг данных в пропсы. View — dumb:
только JSX из компонентов UI Kit и пропсы, без эффектов и запросов,
тестируется одними пропсами.

**TypeScript.** Запрещены `any`, non-null `!` и `@ts-ignore`: сужать через
type guards и `?.`, тип чинить, а не подавлять. Пропсы объявляются через
`interface` над компонентом, не инлайном. Возвращаемый тип хука указывается
явно. Типы ответов API берутся из `assets/api/schema.d.ts`, а при отсутствии
генерации — из `<feature>.types.ts`.

**Данные.** Все запросы идут через `apiFetch` из `assets/api/client.ts`, raw
`fetch` в компонентах запрещён. Хук возвращает безопасные дефолты (`?? []`,
`?? 0`, `?? {}`), чтобы View не падал на `undefined`. Когда появится TanStack
Query: ключ строится как `[module, entity, ...params]`, после мутации
вызывается `invalidateQueries` по тому же префиксу.

**Формы.** Целевая связка — React Hook Form и Zod, схема в
`<feature>.schema.ts` как единственный источник правды, тип через `z.infer`.
Пока библиотеки не установлены, формы пишутся на нативных элементах с теми же
правилами вывода ошибок: класс ошибки поля и сообщение берутся из UI Kit
(`input-error`, `form-error`), серверная 422 раскладывается по полям.

## Twig-острова

Twig отдаёт shell, React монтируется точечно. Контракт монтирования:

```twig
<div
  data-island="reconciliation-kpi"
  data-props="{{ { period: period }|json_encode|e('html_attr') }}"
></div>

{{ vite_entry_script_tags('reconciliation_page') }}
```

Правила:

- `data-props` всегда через `|json_encode|e('html_attr')`. Без экранирования
  это XSS.
- Монтирование защищено проверкой существования элемента.
- В entrypoint только монтирование: ни бизнес-логики, ни хуков.
- CSRF-токен приходит в `data-props` или в meta-теге, который читает
  `api/client.ts`.
- Виджет оборачивается в провайдеры и ErrorBoundary. Ни `mountIsland`, ни
  `AppProviders` пока не написаны: до их появления обёртка делается явно в
  самом entry-файле.

Новый Vite entry добавляется в `input` в `vite.config.js` (файл именно `.js`).
Имена существующих entry — snake_case (`marketplace_analytics_page`), новые
именовать так же, пока весь список не переименован разом.

## Границы модулей

```
ui-kit         ← не импортирует из react/
react/shared   ← только ui-kit и api
react/modules  ← ui-kit, shared, api; не другой модуль
entrypoints    ← модули и провайдеры
_legacy        ← карантин, импортируется только из entrypoints/
```

Зоны заданы машинно в `site/.eslintrc.cjs` через `import/no-restricted-paths`;
читать конфиг, а не копию в документации. Общее между модулями выносится в
`shared/`, а не импортируется напрямую.

`assets/react/_legacy/` — карантин. Правка legacy-файла означает задачу на
миграцию в `modules/`, а не точечный патч. После миграции файлы из `_legacy/`
удаляются в том же PR: две параллельные реализации запрещены. Цель — пустой
`_legacy/`, сейчас в нём 100 файлов.

## Naming

| Сущность | Правило | Пример |
|---|---|---|
| Компонент | PascalCase | `KpiSummaryView.tsx` |
| Хук | camelCase с `use` | `useKpiSummary.ts` |
| Типы | camelCase с `.types` | `kpi-summary.types.ts` |
| Zod-схема | camelCase с `.schema` | `plan-selection.schema.ts` |
| CSS Module | `.module.css` | `KpiSummaryView.module.css` |
| Vite entry | snake_case, как в существующих | `marketplace_analytics_page` |
| Имя острова | kebab-case | `reconciliation-kpi` |
| Папка модуля и фичи | kebab-case | `marketplace-analytics/`, `kpi-summary/` |
| Константы маршрутов API | SCREAMING_SNAKE | `API_RECONCILIATION_KPI` |

## Чего не делать

```tsx
// ❌ fetch внутри компонента
useEffect(() => { fetch('/api/data').then(...) }, []);

// ❌ any
const handler = (e: any) => {};

// ❌ бизнес-логика в entrypoint — там только монтирование

// ❌ общее состояние через глобалы
window.cartCount = 5;

// ❌ импорт фичи одного модуля из другого — общее через shared/

// ❌ data-* без экранирования
data-props="{{ data|json_encode }}"

// ❌ хардкод цвета вместо класса UI Kit
<span style={{ color: '#047857' }}>Active</span>
<span className="status status--success"><span className="dot" /> Active</span>

// ❌ свой класс вне UI Kit
<div className="my-custom-card">

// ❌ свой CSS для статусов, кнопок, карточек — всё из ui-kit/

// ❌ импорт всех иконок пакетом — ломает tree-shaking
import * as Icons from '@tabler/icons-react';
```

Ещё запрещено: `console.log` в коммитах (только `console.error` в обработчике
ошибок), inline-стили со значениями вместо токенов, кастомные спиннеры и
пустые состояния вместо компонентов UI Kit, управление модалками и дропдаунами
чужим JS вместо React-состояния.

## Связанные документы

- `AGENTS.md` — workflow, полномочия, ревью, одно одобрение «merge and deploy».
- `docs/workflow/stage-report-frontend.md` — фронтовый self-review checklist.
- `screen-intake.md` в корне репозитория — анализ нового макета от дизайнера.
- `site/ui-kit/storybook.html` — визуальный референс,
  `site/ui-kit/decisions.md` — ADR дизайн-системы,
  `site/ui-kit/CHANGELOG.md` — версия и журнал изменений,
  `site/ui-kit/README.md` — обзор для дизайнера.
- `site/screens/README.md` — матрица реализации макетов.
