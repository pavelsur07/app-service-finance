# CLAUDE.frontend.md — Frontend Rules

Правила фронтенда: Symfony + Twig + React + Vite. Эталон визуала обоих
интерфейсов — дизайн-система «Ваш Финдир» (`docs/design-system/sheets/`).

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
| `assets/react/modules/<module>/features/*` | два — `modules/budget/features/budget-grid/` (04.11.2026), `modules/marketplace-analytics/features/unit-extended/` («Unit — Экономика», 09.11.2026); остальной React в `assets/react/_legacy/`, 72 файла (09.11.2026) |
| `assets/react/entrypoints/` | `budget_grid_page.tsx`, `unit_extended_page.tsx`; остальные entry указывают прямо на файлы `_legacy/`. CSS Modules острова подключаются в шаблоне `vite_entry_link_tags('<entry>')` в блоке `stylesheets` — без него стили не придут |
| `mountIsland`, `AppProviders`, `ErrorBoundary` | первые два не реализованы; `ErrorBoundary` — `shared/ui/ErrorBoundary.tsx` (09.11.2026) |
| `apiFetch` в `assets/api/client.ts` | есть с 04.11.2026: JSON, `X-CSRF-Token`, ошибка `{error:{code,message}}` → `ApiError`, 401 → вход; `signal` — отмена (`AbortError` как есть) |
| `templates/_macros/ui-kit.html.twig` | нет |
| React-обёртки кита | одна: `assets/react/ui-kit/Button/` |
| `assets/react/shared/` | `lib/cn.ts`; `ui/ErrorBoundary.tsx`; `format/number.ts` (`formatMoney`/`formatQty`, легаси `utils.ts` реэкспортирует); `period/` — фильтр периода по DS (`PeriodFilter`: пресеты + окно «Дата с / по», как «Транзакции ДДС»), с 09.11.2026 на «Unit — Экономика» и старой юнит-экономике |

Не установлены и потому не применяются: TanStack Query, React Hook Form, Zod,
`clsx`, тест-раннер. Установка любой из них — отдельное одобрение Владельца
(`AGENTS.md` §3.3), а не решение внутри задачи.

## Гейты — что есть и чего нет

```bash
npm run lint                        # eslint, --max-warnings=0
npm run build                       # vite build
npm run api:types:check             # schema.d.ts синхронна с OpenAPI
npm run test:unit                   # vitest: чистые функции, assets/**/*.test.ts
make site-cs-twig                   # twigcs (site/.twigcs.php) + lint:twig, CI — twig-lint.yml
```

`test:unit` (vitest, окружение `node`, конфиг `vitest.config.ts`) — только для
чистой логики без DOM и React-рендера: разбор и форматирование сумм, выбор
разреза, расчёты острова. Тест лежит рядом с модулем (`amount.ts` →
`amount.test.ts`); CI гоняет его в job `eslint-boundaries`. Чистую функцию с
ветвлениями, вынесенную из компонента, покрывать тестом в том же PR. Тестов
компонентов (jsdom, Testing Library) нет — не ставить без отдельного решения.

Скрипта `typecheck` нет: типы проверяются только тем, что `tsc` делает внутри
`vite build`. Не писать в отчётах, что он прогнан. Обязательная проверка
фронтового изменения — `lint`, `build`, `test:unit` (плюс `api:types:check`,
если менялся контракт API) и ручной smoke затронутой страницы. Проверок
`check:ui-kit` и `check:uikit-react-mapping` больше нет: удалены вместе со
старым UI Kit 2026-10-11, замена не ставилась.

`tsconfig.json`: включён `strict`. Флагов `noUncheckedIndexedAccess`,
`exactOptionalPropertyTypes`, `noImplicitReturns`,
`noFallthroughCasesInSwitch` там нет — не ссылаться на них как на данность.

Node и npm живут только в контейнере, на хосте их нет.

## Источники правды

| Что | Источник правды | Где живёт |
|---|---|---|
| Визуал обоих интерфейсов | Дизайн-система «Ваш Финдир»: токены, контролы, таблицы, деньги, вкладки, обратная связь | `docs/design-system/sheets/*.dc.html` (читать как исходник: значения — из `<style>` и инлайн-стилей) |
| Behavior layer | Поведение браузера и a11y: `:disabled`, `:read-only`, `:autofill`, focus | `assets/styles/kit/components/states.css`, владелец — код |
| Screens | Спецификация страниц от дизайнера | `site/screens/<name>.html` |
| Код | Реализация | `templates/`, `assets/`, `src/` |

Реализация DS в коде — не эталон, а его воплощение:

| Интерфейс | Реализация |
|---|---|
| Легаси (`_layout/legacy.html.twig`, Tabler) | `assets/styles/design-tokens.css` (токены `--vf-*`, слой `--tblr-*`), `assets/styles/vf-custom-classes.css`, Twig-фильтры `vf_money`/`vf_amount`/`vf_number`/`vf_minor` (`src/Twig/VfMoneyExtension.php`), partials `_pagination`, `_tabs`, `_period_presets`, `_confirm_modal` (подтверждение вместо `confirm()`, `assets/confirm_modal.js`) |
| Новый слой (`_layout/app.html.twig`, React) и админка | CSS кита в `assets/styles/kit/` (подключается через `assets/styles/app.css`; значения токенов — DS), те же Twig-фильтры денег, функции `vf_currency_symbol`/`vf_currency_digits`, partials `app/_ui/_pagination`, `app/_ui/_tabs` |

- Код расходится с листом DS — виноват код, в обоих интерфейсах. Новый слой на DS
  с 2026-10-12 (`docs/plan/ds-new-layer.md`): имена токенов кита прежние, значения —
  с листов.
- Деньги в обоих интерфейсах — только `vf_money` / `vf_amount` / `vf_money_short`
  (KPI), не деньги — `vf_number` (лист DS-Money: U+00A0, запятая, U+2212, копейки
  приглушены, «₽» в заголовке колонки, красный для сумм не используется). Копейки
  учёта в валюте с другой точностью — `minor|vf_minor(vf_currency_digits(cur))`,
  символ для заголовка — `vf_currency_symbol(cur)`.
- Пагинация и вкладки разделов нового слоя — только `app/_ui/_pagination` и
  `app/_ui/_tabs` (больше 6 — в «Ещё»).
- Выбор из справочника в легаси (поле формы, пилюля фильтра) — только ядро
  `picker_core.js` и его виды: `docs/design-system/components/entity-picker.md`.
  Контрагент в форме — `CounterpartyPickerType` с `'browse' => true` (окно как у пилюли).
- Лист DS не покрывает случай — ближайшее решение по существующему коду и
  `TODO(ds)` в месте решения, вопрос — в отчёт.
- Поручение Владельца расходится с листом — решение записать (см.
  `docs/plan/ds-legacy-tabler-audit.md` §7a как образец).

Behavior layer (`states.css`) принадлежит коду: там поведение браузера, которого
в макете нет. Новых классов он не вводит, только псевдоклассы на существующих.

## CSS нового слоя (`assets/styles/kit/`)

**Токены.** Raw-токены в `kit/tokens/colors.css`, `typography.css`,
`spacing.css`, `radius.css`, `shadows.css`; semantic-токены в `semantic.css`.
В компонентах кита и в feature-стилях использовать только semantic-токены.
Raw-токен напрямую в компоненте запрещён, хардкод цвета запрещён везде, кроме
`tokens/colors.css` (в легаси — кроме `design-tokens.css`).

**Классы.** В Twig, React и CSS Modules нового слоя — классы кита
(`assets/styles/kit/components`, `patterns`). Кастомные `.my-button`,
`.special-card` в модулях запрещены. Мелкий локальный оверрайд — CSS Module с
классом-обёрткой:

```css
/* KpiSummaryView.module.css */
.root { padding-top: var(--space-4); }
```

Внутри CSS Module нельзя переопределять `.btn`, `.card` и прочие классы кита,
только обёртки. Понадобился один и тот же оверрайд в двух модулях — нужен новый
вариант компонента в ките, а не вторая копия CSS.

**React-обёртки** живут в `assets/react/ui-kit/<Name>/<Name>.tsx`. Обёртка не
пишет свой CSS: она типизирует пропсы, нормализует классы, делает мелкую логику
вроде `disabled` при `loading`. Классы мерджатся хелпером `cn` из
`@/react/shared/lib/cn` (`clsx` не установлен). В JSDoc — ссылка на CSS
компонента в `assets/styles/kit/` и на лист DS. Образец —
`assets/react/ui-kit/Button/Button.tsx`.

Изменение токенов, добавление, удаление или переименование класса в
`assets/styles/kit/components/*` и `patterns/*`, добавление компонента в
`assets/react/ui-kit/` — HIGH-LOCAL: усиленные проверки и внимательный review,
без остановки. Удаление или переименование класса — сначала найти всех
потребителей (`rg` по `templates/` и `assets/`).

## React

**Smart / Dumb.** Нетривиальная фича делится минимум на два файла. Widget —
smart: хук, состояние, обработчики, маппинг данных в пропсы. View — dumb:
только JSX из компонентов кита и пропсы, без эффектов и запросов,
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
правилами вывода ошибок: класс ошибки поля и сообщение берутся из кита
(`input-error`, `form-error`), серверная 422 раскладывается по полям.

## Twig-острова

Twig отдаёт shell, React монтируется точечно. Контракт монтирования:

```twig
<div
  data-island="verification-coverage"
  data-props="{{ { period: period }|json_encode|e('html_attr') }}"
></div>

{{ vite_entry_script_tags('ingestion_verification_coverage_page') }}
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
ui-kit         ← не импортирует из react/   (assets/react/ui-kit — обёртки)
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
`_legacy/`, сейчас в нём 72 файла.

## Naming

| Сущность | Правило | Пример |
|---|---|---|
| Компонент | PascalCase | `KpiSummaryView.tsx` |
| Хук | camelCase с `use` | `useKpiSummary.ts` |
| Типы | camelCase с `.types` | `kpi-summary.types.ts` |
| Zod-схема | camelCase с `.schema` | `plan-selection.schema.ts` |
| CSS Module | `.module.css` | `KpiSummaryView.module.css` |
| Vite entry | snake_case, как в существующих | `marketplace_analytics_page` |
| Имя острова | kebab-case | `verification-coverage` |
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

// ❌ хардкод цвета вместо класса кита
<span style={{ color: '#047857' }}>Active</span>
<span className="status status--success"><span className="dot" /> Active</span>

// ❌ свой класс вне кита
<div className="my-custom-card">

// ❌ свой CSS для статусов, кнопок, карточек — всё из assets/styles/kit/

// ❌ импорт всех иконок пакетом — ломает tree-shaking
import * as Icons from '@tabler/icons-react';
```

Ещё запрещено: `console.log` в коммитах (только `console.error` в обработчике
ошибок), inline-стили со значениями вместо токенов, кастомные спиннеры и
пустые состояния вместо компонентов кита, управление модалками и дропдаунами
чужим JS вместо React-состояния.

## Связанные документы

- `AGENTS.md` — workflow, полномочия, ревью, одно одобрение «merge and deploy».
- `docs/workflow/stage-report-frontend.md` — фронтовый self-review checklist.
- `docs/workflow/twig-conventions.md` — Twig: shell'ы, партиалы, что в шаблоне не живёт, гейт `make site-cs-twig`.
- `screen-intake.md` в корне репозитория — анализ нового макета от дизайнера.
- `docs/design-system/sheets/` — листы дизайн-системы «Ваш Финдир» (эталон),
  `docs/design-system/README.md` — как их читать.
- `docs/plan/ds-legacy-tabler-audit.md` — решения по DS и расхождения с поручением.
- `site/screens/README.md` — матрица реализации макетов.

## Самостоятельные frontend-приложения (2026-11-11)

`apps/account/` и `apps/admin/` — React 19 SPA, локальная подготовка интерфейсов.
Правила выше про React 18, отсутствие typecheck и список установленных библиотек
относятся к `site/`. Новые приложения имеют независимые npm lock-файлы,
строгий typecheck, ESLint/Prettier, Vitest/MSW и Playwright. Команды и границы:
`apps/README.md`. Общие UI/API исходники — `site/packages/` (внутри старого
Docker build context); токены/CSS по-прежнему в `assets/styles/kit/`.
Старые API/Button/cn импорты совместимы; только старый API-адаптер перенаправляет
на `/login`. Account/admin используют same-origin Symfony session API с отдельными host-only
сессиями. Оркестрация находится в приложениях; shared API не делает redirect.
Публичные about/404 не выполняют bootstrap. Команды реального HTTP E2E и
изолированного локального входа описаны в `apps/README.md`.
