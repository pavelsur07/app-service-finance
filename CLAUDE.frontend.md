# CLAUDE.frontend.md — Frontend Rules

Правила разработки фронтенда для проекта на Symfony + Twig + React + Vite, с собственной дизайн-системой (UI Kit) как живым документом.

> **Режим работы — автономный.** Claude выполняет задачу этапами, каждый этап завершает self-review + Stage Report. Высокорисковые этапы — обязательная остановка для ревью Владельцем. Без явной спецификации задача не стартует. Без апрува плана код не пишется.

---

## 1. Стек

- **Backend:** Symfony (PHP), Twig-шаблоны.
- **Frontend:** React 18 + TypeScript (strict), Vite.
- **Стили:** собственный UI Kit (CSS-классы и токены из `ui-kit/`). **Никакого Tabler / Bootstrap.**
- **Иконки:** SVG-спрайт из `ui-kit/icons/` (или `@tabler/icons-react` поштучно — если решено оставить, см. ADR).
- **Запросы:** TanStack Query (React Query v5).
- **Формы:** React Hook Form + Zod.
- **HTTP:** Native `fetch` через централизованный `assets/api/client.ts`.
- **CSS:** глобальные классы из UI Kit + CSS Modules для feature-локальных оверрайдов. Никаких inline-стилей с хардкодом цветов.
- **Stimulus:** допустим **только** для Twig-only страниц с лёгким интерактивом (show/hide, copy-to-clipboard). Любая логика с запросами к API — React-остров.

---

## 2. Источники правды

В проекте три слоя, каждый — единственный источник правды для своего:

| Слой | Источник правды для | Где живёт |
|---|---|---|
| **UI Kit** | Визуальный язык: классы, токены, компоненты | `ui-kit/` (HTML + CSS + decisions.md + CHANGELOG.md) |
| **Screens** | Спецификация конкретных страниц от дизайнера | `screens/<name>.html` |
| **Code** | Реализация: Twig + React + Symfony | `templates/`, `assets/`, `src/` |

**Правила несовпадений:**

- Код противоречит UI Kit (использует класс, которого нет в DS) → виноват код, фиксится код. Lint в CI ловит это автоматически.
- Screen противоречит UI Kit (использует класс, которого нет в DS) → возвращаем дизайнеру или дополняем UI Kit, **до** реализации.
- UI Kit противоречит сам себе (два компонента делают одно и то же) → правится `ui-kit/decisions.md`, фиксируется решение, обновляется CHANGELOG.

Код **никогда не является источником правды**. Если в коде есть класс, которого нет в UI Kit, — это баг, не фича.

---

## 3. Структура проекта

```
site/
├── ui-kit/                                  ← дизайн-система (источник правды для UI)
│   ├── tokens/
│   │   ├── colors.css                       ← raw tokens: --color-primary, --bank-*, --src-*
│   │   ├── typography.css                   ← --font-family, --font-*
│   │   ├── spacing.css                      ← --space-*
│   │   ├── radius.css                       ← --r-*
│   │   ├── shadows.css                      ← --shadow-*
│   │   ├── semantic.css                     ← semantic tokens: --button-primary-bg, --card-bg, --form-error-text
│   │   └── index.css                        ← @import всех файлов выше + :root { ... }
│   ├── components/                          ← примитивы (atoms)
│   │   ├── button.html + button.css
│   │   ├── input.html + input.css
│   │   ├── card.html + card.css
│   │   ├── badge.html + badge.css
│   │   ├── status.html + status.css
│   │   ├── chip.html + chip.css
│   │   └── all.css                          ← @import всех компонентов
│   ├── patterns/                            ← композиты (molecules)
│   │   ├── kpi-row.html + kpi-row.css
│   │   ├── filter-bar.html + filter-bar.css
│   │   ├── page-header.html + page-header.css
│   │   ├── empty-state.html + empty-state.css
│   │   └── all.css
│   ├── icons/                               ← SVG-спрайт
│   ├── storybook.html                       ← живой документ для дизайнера и команды
│   ├── decisions.md                         ← ADR дизайна
│   ├── design-audit.md
│   ├── CHANGELOG.md                         ← semver-журнал изменений DS
│   ├── promts/                              ← промпты для дизайн-AI (если используете)
│   └── README.md
│
├── screens/                                 ← HTML-макеты от дизайнера (спецификации страниц)
│   ├── _analysis/                           ← отчёты screen-intake task
│   │   └── reconciliation.md
│   ├── _archive/                            ← предыдущие версии макетов
│   │   └── dashboard-2026-05.html
│   ├── login.html                           ← с frontmatter (uiKit version, date, source)
│   ├── dashboard.html
│   ├── reconciliation.html
│   └── README.md                            ← матрица: какой screen реализован, где, статус
│
├── assets/
│   ├── api/
│   │   ├── client.ts                        ← apiFetch + CSRF + 401/422 handling
│   │   ├── schema.d.ts                      ← codegen из OpenAPI
│   │   └── README.md
│   ├── controllers/                         ← Stimulus (только для Twig-only лёгкого интерактива)
│   ├── styles/
│   │   └── app.css                          ← @import '../../ui-kit/tokens/index.css';
│   │                                            @import '../../ui-kit/components/all.css';
│   │                                            @import '../../ui-kit/patterns/all.css';
│   └── react/
│       ├── ui-kit/                          ← React-обёртки над классами UI Kit (1:1 с ui-kit/components и ui-kit/patterns)
│       │   ├── Button/
│       │   │   ├── Button.tsx
│       │   │   ├── Button.test.tsx          ← тест рядом с кодом
│       │   │   └── index.ts
│       │   ├── Card/
│       │   ├── Kpi/
│       │   ├── Status/
│       │   ├── KpiRow/                      ← обёртка над паттерном
│       │   └── index.ts                     ← публичный экспорт
│       ├── modules/                         ← бизнес-домены (bounded contexts)
│       │   ├── dashboard/
│       │   ├── marketplace-analytics/
│       │   ├── reconciliation/
│       │   ├── ingestion-verification/
│       │   └── marketplace-ads/
│       ├── shared/                          ← cross-module утилиты (не дизайн-система!)
│       │   ├── inputs/                      ← доменные инпуты: MoneyInput, BankSelector, DateRangePicker
│       │   ├── format/                      ← formatMoney, formatDate, formatPercent
│       │   ├── hooks/                       ← useTenant, useUser, useDebounce
│       │   ├── http/                        ← обёртки над api/client.ts (queryFn helpers)
│       │   ├── i18n/                        ← переводы для React
│       │   └── lib/
│       ├── app/                             ← bootstrap, провайдеры
│       │   ├── providers.tsx                ← QueryClientProvider, I18nProvider, ErrorBoundary
│       │   ├── ErrorBoundary.tsx
│       │   └── mountIsland.tsx              ← универсальный mount-хелпер для островов
│       ├── entrypoints/                     ← ТОЛЬКО mount-логика
│       │   ├── dashboard.tsx
│       │   ├── marketplace-analytics.tsx
│       │   ├── reconciliation.tsx
│       │   └── ingestion-verification.tsx
│       └── _legacy/                         ← карантин для старого кода (см. раздел «Migration»)
│
├── templates/
│   ├── _macros/
│   │   └── ui-kit.html.twig                 ← Twig-макросы для UI Kit (button, status, kpi)
│   ├── _layout/
│   └── <module>/                            ← Twig-шаблоны модулей
│
├── src/                                     ← Symfony
├── public/
│   └── build/                               ← Vite output (gitignored)
├── tools/
│   ├── check-ui-kit-classes.mjs             ← lint: классы в коде есть в UI Kit
│   ├── check-uikit-react-mapping.mjs        ← lint: каждый ui-kit/components/* имеет React-обёртку и наоборот
│   └── codegen/                             ← OpenAPI → TS types
├── tests/                                   ← e2e (Playwright); unit-тесты живут рядом с кодом
└── docs/
    └── tasks/                               ← планы и отчёты автономных задач
        └── <id>/
            ├── plan.md
            ├── api-contract.md
            ├── stages/
            │   ├── stage-F1.md
            │   ├── stage-F2.md
            │   └── ...
            └── handoff.md
```

---

## 4. Автономный workflow

### Источник задачи

Каждая задача начинается со **спецификации** одного из двух типов:

1. **Screen-intake task** — пришёл новый HTML-макет от дизайнера. Спецификация — сам файл `screens/<name>.html`. Правила выполнения — в `screen-intake.md` (отдельный документ). Это **не реализация**, это анализ. Результат — отчёт в `screens/_analysis/<name>.md`.

2. **Implementation task** — реализация фичи на основе утверждённого анализа screen или прямого брифа Владельца. Спецификация — `docs/tasks/<id>/TASK.md` или чёткий бриф Владельца с scope и acceptance criteria. Правила — этот документ, разделы 5–10.

Без спецификации → 🛑 **STOP, попросить её**. Догадки и автоматическое расширение scope — запрещены.

### Общая последовательность фаз

```
Phase 0 (Plan)  →  Phase F1..F5 (Execute)  →  Phase Final (Handoff)
       ↑                  ↑
   STOP, апрув     после каждого этапа: self-review + Stage Report
   Владельца       high-risk → 🛑 STOP, ждать Владельца
                   self-review red → fix или 🛑 STOP
```

### Phase 0 — Plan (для implementation task)

1. Прочитать: `CLAUDE.frontend.md`, спецификацию задачи, утверждённый отчёт `screens/_analysis/<screen>.md` (если задача от screen).
2. Прочитать `ui-kit/CHANGELOG.md` — что недавно изменилось в DS.
3. Найти 2–3 похожих feature-слайса в `assets/react/modules/*/features/*`, опереться на их паттерны.
4. Составить план в `docs/tasks/<id>/plan.md`:
    - **Дерево компонентов:** Widget (smart) → View (dumb) → компоненты из `react/ui-kit/` → классы UI Kit.
    - **Список переиспользуемых vs новых компонентов.** Для каждого нового — обоснование (почему не подошёл существующий примитив/паттерн).
    - **API-интеграция:** эндпоинты, хуки (`useX`, `useUpdateX`), типы (из `schema.d.ts` или ручные).
    - **Mount-контракт:** новые Vite entry / Twig mount points + `data-*` атрибуты.
    - **Список этапов** (F1–F5) с риск-классом.
    - **Тесты,** которые потребуются.
5. 🛑 **STOP. Ждать апрува плана Владельцем.** Без подтверждения — не писать код.

### Типовая декомпозиция этапов

| Stage | Цель | Типовой риск |
|---|---|---|
| **F1 — Types & API** | `feature.types.ts`, Zod-схемы (если формы), хуки на React Query (`useX`, `useUpdateX`). Безопасные дефолты. | 🟡 MEDIUM |
| **F2 — UI components** | Только **новые** компоненты. Сначала проверить `react/ui-kit/` + `react/shared/`. Каждый новый компонент тестируется только пропсами. | 🟢 LOW / 🟡 MEDIUM |
| **F3 — Widget + View** | Smart-контейнер использует hook из F1, View рендерит UI. Loading / error / empty состояния через `EmptyState` / `Spinner` из UI Kit. | 🟡 MEDIUM |
| **F4 — Entrypoint + Twig integration** | Новый файл в `entrypoints/`, запись в `vite.config.ts` rollupOptions, Twig mount point с `\|e('html_attr')`, `vite_entry_script_tags`, `ErrorBoundary` оборачивает виджет. | 🔴 **HIGH** |
| **F5 — Tests + final review** | Unit-тесты на dumb-компоненты и хуки. Smoke на entrypoint. Финальный handoff. | 🟢 LOW (но финальный STOP всегда) |

### Phase Final — Handoff

1. Прогнать полный набор: `npm run lint && npm run typecheck && npm run test && npm run build && npm run check:ui-kit`.
2. Сверить построчно «Self-review checklist» (раздел 14) и «What NOT to do» (раздел 15).
3. Заполнить `docs/tasks/<id>/handoff.md`:
    - summary всех этапов,
    - список новых Vite entries + Twig mount points,
    - список изменений в shared-инфраструктуре (`apiClient.ts`, `queryClient.ts`, `vite.config.ts`, `ui-kit/tokens/*`),
    - список новых npm-зависимостей с обоснованием,
    - bump-версии UI Kit, если затронут (с записью в `ui-kit/CHANGELOG.md`),
    - размер бандла до/после,
    - риски и follow-ups, вынесенные за scope.
4. 🛑 **STOP. Final Owner review.** Merge — только после одобрения Владельцем.

---

## 5. Классификация этапов по риску

| Риск | Примеры | Поведение после self-review |
|---|---|---|
| 🟢 **LOW** | Рефакторинг внутри одного dumb-компонента; добавление иконки / empty-state текста; добавление feature-scoped CSS Module override; добавление unit-тестов; обновление документации | Self-review зелёный → **продолжать автономно** |
| 🟡 **MEDIUM** | Новый feature-слайс (Widget+View+hook+types) внутри существующего entrypoint; новая форма с Zod; новый hook на React Query; новая Modal/Card композиция из существующих UI Kit компонентов | Self-review зелёный → **продолжать автономно**, Stage Report в `docs/tasks/<id>/stages/` |
| 🔴 **HIGH** | Новый Vite entry / новый widget mount point; изменения в `api/client.ts` / `app/providers.tsx` / `vite.config.ts`; правки `ui-kit/tokens/*` (даже одной CSS-переменной); добавление/удаление/переименование класса в `ui-kit/components/*` или `ui-kit/patterns/*`; добавление компонента в `react/ui-kit/`; новые npm-зависимости; изменения CSRF / auth flow; изменение публичного API виджета | Self-review зелёный → 🛑 **STOP, обязательное ревью Владельцем перед следующим этапом** |

Если затрудняешься классифицировать — считай **HIGH** и остановись.

---

## 6. Обязательные точки STOP

Никогда не продолжать без явного апрува Владельца:

```
перед добавлением нового Vite entry в rollupOptions.input
перед изменением vite.config.ts, tsconfig.json
перед изменением assets/api/client.ts (касается всех фич)
перед изменением assets/react/app/providers.tsx (касается всех виджетов)
перед изменением Twig-шаблона с mount point виджета
перед изменением CSRF / auth-флоу
перед изменением ui-kit/tokens/*.css (любая правка токенов)
перед добавлением / удалением / переименованием класса в ui-kit/components/* или ui-kit/patterns/*
перед добавлением нового React-компонента в assets/react/ui-kit/
перед npm install любой новой зависимости
перед удалением: компонента, хука, типа, entrypoint, mount point
перед изменением публичного API виджета (data-attrs контракт, props виджета)
перед bump-версии UI Kit (CHANGELOG.md)
если self-review нашёл проблему, которую не удалось починить за 1 итерацию
если задача требует выйти за изначальный scope
финальный handoff (всегда STOP)
```


---

## 7. UI Kit — правила использования

### 7.1. Токены: raw vs semantic

В `ui-kit/tokens/` два слоя:

**Raw tokens** (`colors.css`, `typography.css`, `spacing.css`, `radius.css`, `shadows.css`):
```css
:root {
  --color-primary: #1A56DB;
  --color-success: #047857;
  --space-2: 8px;
  --r-2: 6px;
}
```

**Semantic tokens** (`semantic.css`):
```css
:root {
  --button-primary-bg: var(--color-primary);
  --button-primary-text: #FFFFFF;
  --card-bg: var(--bg-card);
  --form-error-text: var(--danger-text);
  --status-success-bg: var(--success-bg);
}
```

**Правила:**

- В компонентах UI Kit (`ui-kit/components/*.css`, `ui-kit/patterns/*.css`) использовать **только semantic tokens**.
- В feature-стилях (CSS Modules в модулях) использовать **только semantic tokens или классы UI Kit**.
- Raw tokens **никогда** не используются напрямую в компонентах — только через semantic.
- Хардкод цветов (`#1A56DB`, `rgb(...)`, `hsl(...)`) — **запрещён** везде, кроме `ui-kit/tokens/colors.css`.

Это даёт два преимущества: можно переименовать `--color-primary` без правки всех компонентов; можно сделать dark mode переопределением semantic-токенов в одном файле.

### 7.2. Классы UI Kit

- В коде (Twig, React, CSS Modules) использовать **только классы, определённые в `ui-kit/`**.
- Запрещено создавать кастомные классы вида `.my-button`, `.special-card` в модулях.
- Если для конкретной фичи нужен мелкий визуальный оверрайд (например, отступ) — использовать CSS Module внутри модуля, с именем-неймспейсом:
  ```css
  /* modules/reconciliation/features/kpi-summary/KpiSummaryView.module.css */
  .root { padding-top: var(--space-4); }
  ```
  Внутри CSS Module нельзя переопределять стили классов UI Kit (`.btn`, `.card`). Только обёртки.
- Если оверрайд нужен в нескольких модулях — это сигнал, что нужен новый вариант компонента в UI Kit, а не дублирование CSS Module.

### 7.3. React-обёртки (`assets/react/ui-kit/`)

Для каждого компонента в `ui-kit/components/` и каждого паттерна в `ui-kit/patterns/` должна существовать React-обёртка в `assets/react/ui-kit/<Name>/<Name>.tsx`.

**Контракт обёртки:**

```tsx
// assets/react/ui-kit/Button/Button.tsx
import clsx from 'clsx';

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger';
type Size = 'sm' | 'md' | 'lg';

interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant;
  size?: Size;
  loading?: boolean;
  leadingIcon?: React.ReactNode;
}

/**
 * Button — wrapper over UI Kit `.btn` class.
 *
 * @uiKit ui-kit/components/button.html
 * @version 1.2
 */
export const Button: React.FC<ButtonProps> = ({
  variant = 'primary',
  size = 'md',
  loading = false,
  leadingIcon,
  className,
  disabled,
  children,
  ...rest
}) => (
  <button
    className={clsx('btn', `btn-${variant}`, `btn-${size}`, loading && 'btn-loading', className)}
    disabled={disabled || loading}
    {...rest}
  >
    {leadingIcon}
    {children}
  </button>
);
```

**Правила:**

- Обёртка **не пишет свой CSS**. Все стили — из UI Kit.
- Обёртка только: типизирует пропсы, нормализует классы, ставит `disabled` при `loading`, делает мелкую логику (state для controlled-компонентов).
- JSDoc с `@uiKit` и `@version` — **обязательны**. Скрипт `tools/check-uikit-react-mapping.mjs` проверяет, что для каждого `ui-kit/components/<name>.html` есть React-обёртка с правильным `@uiKit`-указателем, и наоборот.
- Версия в `@version` указывает, какой версии UI Kit обёртка соответствует. При breaking-изменении компонента DS — обновляется здесь же.
- `className` пропс **всегда** мерджится через `clsx`. Никаких `style={{ ... }}` с цветами.

### 7.4. Twig-макросы для UI Kit

Для сложных компонентов с повторяющейся разметкой (например, `status` с `<span class="dot">` + a11y) — в `templates/_macros/ui-kit.html.twig` живут макросы:

```twig
{# templates/_macros/ui-kit.html.twig #}
{% macro status(variant, label) %}
  <span class="status status--{{ variant }}" role="status">
    <span class="dot" aria-hidden="true"></span>
    {{ label }}
  </span>
{% endmacro %}

{% macro button(label, variant = 'primary', size = 'md', attrs = {}) %}
  <button class="btn btn-{{ variant }} btn-{{ size }}"
          {% for k, v in attrs %}{{ k }}="{{ v|e('html_attr') }}" {% endfor %}>
    {{ label }}
  </button>
{% endmacro %}
```

Использование:
```twig
{% import '_macros/ui-kit.html.twig' as ui %}
{{ ui.status('success', 'Активен') }}
{{ ui.button('Сохранить', 'primary', 'md', { type: 'submit' }) }}
```

Простые случаи (`<button class="btn btn-primary">Ок</button>`) — макрос не нужен, пишем напрямую.

### 7.5. Версионирование UI Kit

UI Kit — **живой документ**, но изменения дисциплинированы по semver:

| Тип изменения | Версия | Кто решает | Что требуется |
|---|---|---|---|
| Косметические fix'ы (контраст, тени, мелкие отступы) | patch (1.2.0 → 1.2.1) | Дизайнер + ревью одним фронтом | Запись в CHANGELOG, визуальный регресс-тест в CI |
| Новые компоненты, новые варианты, новые токены, без поломки существующего | minor (1.2.0 → 1.3.0) | Дизайнер + Владелец | Запись в CHANGELOG, обновление React-обёрток (новые), визуальный регресс |
| Переименование классов, удаление вариантов, breaking-изменения токенов | major (1.2.0 → 2.0.0) | Владелец + миграционный план | Запись в CHANGELOG, **отдельная задача на миграцию всех потребителей**, deprecation-период минимум 1 minor |

Любое изменение `ui-kit/*` сопровождается:

1. Записью в `ui-kit/CHANGELOG.md` (формат — см. ниже).
2. Bump-версии в JSDoc-обёртках React-компонентов, если затронуты.
3. Если major — отдельной миграционной задачей.

**Формат `ui-kit/CHANGELOG.md`:**

```markdown
## [1.3.0] — 2026-07-01
### Added
- `KpiRow` pattern (`ui-kit/patterns/kpi-row.*`)
- `--button-ghost-bg` semantic token

### Changed
- `.btn-primary` hover: `#1E40AF` → `#1742B0` (улучшен контраст)

### Deprecated
- `.chip` без модификатора — использовать `.chip--filter`. Удалится в 1.5.

### Migration notes
- Никаких breaking. Обновить можно немедленно.
```

---

## 8. React — правила

### 8.1. Smart / Dumb split

Любая нетривиальная фича делится на минимум два файла:

- **`<Feature>Widget.tsx`** — smart. Хук `use<Feature>`, состояние, обработчики, маппинг данных в пропсы View. Не рендерит UI напрямую, только передаёт пропсы во View.
- **`<Feature>View.tsx`** — dumb. Только JSX из компонентов UI Kit + пропсы. Никаких хуков с побочными эффектами, никаких `fetch`, никакого `useQuery`. Тестируется одними пропсами.

Тривиальная фича (1 компонент, без данных) — может быть одним файлом, но обычно это означает, что её надо положить в `react/ui-kit/` или `react/shared/`, а не в `modules/`.

### 8.2. TypeScript strict

`tsconfig.json`:
```json
{
  "compilerOptions": {
    "strict": true,
    "noUncheckedIndexedAccess": true,
    "exactOptionalPropertyTypes": true,
    "noImplicitReturns": true,
    "noFallthroughCasesInSwitch": true
  }
}
```

**Запрещено:**
- `any` — использовать `unknown` и сужать типы через type guards.
- Non-null assertion `!` — использовать `?.`, явные проверки.
- `@ts-ignore` — фиксить тип, не подавлять.
- Inline-типы в пропсах JSX — `interface` объявляется над компонентом.

**Обязательно:**
- Пропсы через `interface`.
- Возвращаемый тип хуков указан явно.
- Типы API-ответов из `assets/api/schema.d.ts` (codegen) или, если генератор не настроен, отдельные интерфейсы в `<feature>.types.ts`.

### 8.3. Хуки

Хук фичи:
```ts
// modules/reconciliation/features/kpi-summary/useKpiSummary.ts
import { useQuery } from '@tanstack/react-query';
import { apiFetch } from '@/api/client';
import type { KpiSummary } from './kpi-summary.types';

export function useKpiSummary(period: string): {
  data: KpiSummary;
  isLoading: boolean;
  error: unknown;
} {
  const { data, isLoading, error } = useQuery({
    queryKey: ['reconciliation', 'kpi', period],
    queryFn: () => apiFetch<KpiSummary>(`/api/reconciliation/kpi?period=${period}`),
  });

  return {
    data: data ?? { matched: 0, mismatched: 0, missing: 0 },  // safe defaults
    isLoading,
    error,
  };
}
```

**Правила:**

- Хук всегда возвращает **безопасные дефолты** для данных (`?? []`, `?? 0`, `?? { ... }`). View не должен валиться, если `data` ещё `undefined`.
- Query-ключ строится по схеме `[module, entity, ...params]`.
- Мутации после успеха вызывают `queryClient.invalidateQueries({ queryKey: ['module', 'entity'] })`.
- Запросы **только** через `apiFetch` из `assets/api/client.ts`. Никаких raw `fetch`.

---

## 9. Формы

- React Hook Form + Zod.
- Zod-схема — single source of truth. Тип формы выводится через `z.infer<typeof schema>`.
- Схема живёт в `<feature>.schema.ts` внутри feature-папки.

```ts
// modules/billing/features/plan-selection/plan-selection.schema.ts
import { z } from 'zod';

export const planSelectionSchema = z.object({
  planId: z.string().uuid(),
  billingPeriod: z.enum(['monthly', 'yearly']),
  promoCode: z.string().optional(),
});

export type PlanSelectionForm = z.infer<typeof planSelectionSchema>;
```

```tsx
// modules/billing/features/plan-selection/PlanSelectionView.tsx
const { register, handleSubmit, formState: { errors, isSubmitting } } =
  useForm<PlanSelectionForm>({ resolver: zodResolver(planSelectionSchema) });
```

**Правила вывода ошибок:**

- Поля используют CSS-классы UI Kit для ошибок (например, `input-error` из `ui-kit/components/input.css`).
- Сообщение ошибки — отдельный элемент по правилам UI Kit (например, `<div class="form-error">`).
- Серверная ошибка 422 с валидационным map → сетим в форму через `setError(field, { message })`.

---

## 10. Twig-интеграция (острова)

### 10.1. Mount-контракт

Twig отдаёт shell, React монтируется только в местах с интерактивом:

```twig
{# templates/reconciliation/show.html.twig #}
{% extends '_layout/dashboard.html.twig' %}

{% block body %}
  <header class="page-header">
    <h1 class="page-title">{{ 'reconciliation.title'|trans }}</h1>
  </header>

  <main>
    <div
      data-island="reconciliation-filters"
      data-props="{{ {
        period: app.request.get('period', 'month'),
        availablePeriods: availablePeriods,
        csrfToken: csrf_token('reconciliation')
      }|json_encode|e('html_attr') }}"
    ></div>

    <div
      data-island="reconciliation-kpi"
      data-props="{{ { period: app.request.get('period', 'month') }|json_encode|e('html_attr') }}"
    ></div>

    <div
      data-island="reconciliation-transactions"
      data-props="{{ { period: app.request.get('period', 'month') }|json_encode|e('html_attr') }}"
    ></div>
  </main>

  {{ vite_entry_script_tags('reconciliation') }}
  {{ vite_entry_link_tags('reconciliation') }}
{% endblock %}
```

### 10.2. Entrypoint

```ts
// assets/react/entrypoints/reconciliation.tsx
import { mountIsland } from '@/app/mountIsland';
import { FiltersWidget } from '@/modules/reconciliation/features/filters/FiltersWidget';
import { KpiWidget } from '@/modules/reconciliation/features/kpi-summary/KpiWidget';
import { TransactionsWidget } from '@/modules/reconciliation/features/transactions/TransactionsWidget';

mountIsland('reconciliation-filters', FiltersWidget);
mountIsland('reconciliation-kpi', KpiWidget);
mountIsland('reconciliation-transactions', TransactionsWidget);
```

`mountIsland` находит все `<div data-island="<name>">`, читает `data-props`, оборачивает в `<AppProviders><ErrorBoundary>` и монтирует.

### 10.3. Правила

- В `entrypoints/` — **только** mount-логика, никакой бизнес-логики, никаких хуков.
- `data-props` всегда экранируется через `|json_encode|e('html_attr')`. Без этого — XSS-риск.
- Mount защищён `if (el)`.
- Виджет оборачивается в `QueryClientProvider` + `ErrorBoundary` (делается через `AppProviders`).
- CSRF-токен передаётся через `data-props` или meta-тег `<meta name="csrf-token" content="{{ csrf_token('...') }}">`, который читается в `api/client.ts`.

---

## 11. Импорты и границы

Жёсткая иерархия зависимостей. Нарушение → ESLint красный → CI красный.

```
ui-kit         ← никаких импортов из react/
react/shared   ← только ui-kit и api
react/modules  ← ui-kit, shared, api; НЕ другой модуль
entrypoints    ← только модули + app/providers
_legacy        ← карантин, импортируется только из entrypoints/
```

**Конкретно запрещено:**

- `import X from '@/modules/billing/...'` внутри `modules/auth/*` — модули не импортируют друг друга. Общее — через `shared/`.
- Импорт из `_legacy/` в `modules/` — запрещён. `_legacy/` импортируется только из `entrypoints/` (для старых страниц до миграции).
- Импорт из `modules/` внутри `react/ui-kit/` — запрещён. UI Kit ничего не знает про бизнес.
- Импорт из `modules/` внутри `react/shared/` — запрещён.

ESLint-правило:

```json
{
  "import/no-restricted-paths": ["error", {
    "zones": [
      { "target": "assets/react/ui-kit", "from": "assets/react", "except": ["./ui-kit"] },
      { "target": "assets/react/shared", "from": "assets/react/modules" },
      { "target": "assets/react/modules/marketplace-ads", "from": "assets/react/modules", "except": ["./marketplace-ads", "./shared"] },
      { "target": "assets/react/modules/dashboard", "from": "assets/react/modules", "except": ["./dashboard", "./shared"] },
      { "target": "assets/react/modules", "from": "assets/react/_legacy" }
    ]
  }]
}
```

---

## 12. Naming conventions

| Сущность | Правило | Пример |
|---|---|---|
| Component file | PascalCase | `KpiSummaryView.tsx` |
| Hook file | camelCase с префиксом `use` | `useKpiSummary.ts` |
| Type file | camelCase с `.types` | `kpi-summary.types.ts` |
| Zod schema file | camelCase с `.schema` | `plan-selection.schema.ts` |
| CSS Module | `.module.css` | `KpiSummaryView.module.css` |
| Entrypoint file | kebab-case | `reconciliation.tsx`, `marketplace-analytics.tsx` |
| Vite entry name | kebab-case | `reconciliation` |
| Island name (data-island) | kebab-case | `reconciliation-kpi` |
| API route constants | SCREAMING_SNAKE | `const API_RECONCILIATION_KPI = '/api/reconciliation/kpi'` |
| Module folder | kebab-case | `marketplace-analytics/` |
| Feature folder | kebab-case | `kpi-summary/` |
| Task plan / reports | kebab-case | `docs/tasks/<id>/plan.md` |

---

## 13. Stage Report (заполняется в конце каждого этапа)

```markdown
## Stage F<N>: <название> — DONE

**Риск:** 🟢 LOW | 🟡 MEDIUM | 🔴 HIGH
**Следующее действие:** continue autonomously | 🛑 STOP, ждать Владельца

### Что сделано
- ...

### Затронутые файлы
- `assets/react/modules/reconciliation/features/kpi-summary/useKpiSummary.ts` — new
- `assets/react/modules/reconciliation/features/kpi-summary/KpiWidget.tsx` — new
- `assets/react/modules/reconciliation/features/kpi-summary/KpiView.tsx` — new
- `assets/react/entrypoints/reconciliation.tsx` — new (HIGH-risk)
- `vite.config.ts` — modified (new entry `reconciliation`)
- `templates/reconciliation/show.html.twig` — modified (mount points)

### Self-review
- [x] Project Structure / Naming
- [x] TypeScript strict (no any, no @ts-ignore, no `!`)
- [x] UI Kit-first (нет хардкода цветов / spacing, все классы из ui-kit/)
- [x] Smart/Dumb split
- [x] Хук возвращает безопасные дефолты
- [x] apiFetch через api/client.ts (не raw fetch)
- [x] ErrorBoundary оборачивает виджет в entrypoint
- [x] Twig `data-*` экранированы через `|e('html_attr')`
- [x] Bundle size — не вырос неожиданно
- [x] `npm run lint && npm run typecheck && npm run test && npm run check:ui-kit` — green

### Команды для проверки
- `npm run dev` + ручной smoke `/reconciliation`
- `npm run build` — нет warning, manifest сгенерирован

### Риски / на что обратить внимание ревьюеру
- Добавлен новый Vite entry — проверить `vite_entry_script_tags('reconciliation')` в Twig.

### Открытые вопросы
- нет
```

---

## 14. Self-review checklist

Запускать в строгом порядке. Если хоть один пункт красный — этап **не закрыт**, fix или 🛑 STOP.

**Структура и архитектура:**
- [ ] Изменения строго в рамках цели этапа (нет out-of-scope правок)
- [ ] Структура файлов и naming соблюдены (раздел 12)
- [ ] Нет импортов из `modules/X` внутри `modules/Y` — общее через `shared/`
- [ ] Нет импортов из `_legacy/` в `modules/`
- [ ] `entrypoints/` содержат **только** mount-логику
- [ ] Smart/Dumb split соблюдён для нетривиальной фичи

**TypeScript:**
- [ ] Нет `any`, нет `@ts-ignore`, нет non-null `!`
- [ ] Props через `interface`, не inline
- [ ] Hook возвращает безопасные дефолты (`?? []`, `?? 0`, `?? { ... }`)
- [ ] `npm run typecheck` — green

**UI Kit:**
- [ ] Все CSS-классы в коде существуют в `ui-kit/components/` или `ui-kit/patterns/`
- [ ] `npm run check:ui-kit` — green (классы в коде совпадают с DS)
- [ ] Нет hardcoded hex/rgb цветов в JSX, CSS Modules, inline styles
- [ ] Нет inline `style={{ color: ..., padding: ... }}` со значениями вместо токенов
- [ ] Status/label цвета через UI Kit классы (`.status--success`, `.badge--danger`)
- [ ] Иконки из `ui-kit/icons/` (или поштучно из библиотеки иконок, если решено)
- [ ] Loading-состояния через `Spinner` / `Skeleton` из `react/ui-kit/`, не custom
- [ ] Empty-states через `EmptyState` из `react/ui-kit/patterns/`, не custom
- [ ] Modals/dropdowns управляются React-стейтом, не через раскрытие классов сторонним JS
- [ ] Формы используют классы UI Kit для ошибок (`input-error` + `form-error`)
- [ ] CSS Modules только для component-scoped offset/layout, не для переопределения классов UI Kit

**Data & API:**
- [ ] Все запросы через `apiFetch` из `api/client.ts`, не raw `fetch`
- [ ] `useQuery` / `useMutation` использованы корректно, `invalidateQueries` после мутаций
- [ ] Формы используют React Hook Form + Zod, тип через `z.infer`
- [ ] `isPending` / `isLoading` обработаны в UI (нет залипающих кнопок)
- [ ] 401 → redirect на login; 422 → показ валидационных ошибок через `setError`

**Mount-контракт (если затронут):**
- [ ] Новый Vite entry добавлен в `rollupOptions.input`
- [ ] Twig `data-*` экранированы `|e('html_attr')`
- [ ] Mount защищён `if (el)`
- [ ] Виджет обёрнут в `QueryClientProvider` + `ErrorBoundary` (через `AppProviders`)
- [ ] CSRF meta-тег или `data-props.csrfToken` присутствует в Twig

**UI Kit обёртки (если затронуты):**
- [ ] JSDoc `@uiKit <путь>` + `@version <X.Y>` присутствует
- [ ] `npm run check:uikit-react-mapping` — green
- [ ] Не пишет свой CSS (только использует классы DS)
- [ ] `className` мерджится через `clsx`

**Качество кода:**
- [ ] `npm run lint` — green
- [ ] `npm run test` — green
- [ ] `npm run build` — green, бандл не вырос неожиданно (>10% — обосновать)
- [ ] Нет `console.log` в коммитах (только `console.error` в `ErrorBoundary` / `catch`)

**Stage Report:**
- [ ] Stage Report создан и сохранён в `docs/tasks/<id>/stages/stage-F<N>.md`
- [ ] Коммит сделан с Conventional Commits префиксом, сообщение отражает цель этапа

---

## 15. What NOT To Do

```tsx
// ❌ Never fetch inside a component directly
const MyComponent = () => {
  useEffect(() => { fetch('/api/data').then(...) }, []);
};

// ❌ Never use `any`
const handler = (e: any) => {};

// ❌ Never put business logic in entrypoints
// entrypoints/ = mount only, nothing else

// ❌ Never share state between widgets via globals
window.cartCount = 5; // NO

// ❌ Never import feature A from feature B
import { useFilters } from '@/modules/billing/features/filters/useFilters';
// inside modules/auth/ — NO

// ❌ Never hardcode strings in Twig data attributes without escaping
data-props="{{ data|json_encode }}"  {# Missing |e('html_attr') — XSS risk #}

// ❌ Never hardcode colors — use UI Kit classes or semantic tokens
<span style={{ color: '#047857' }}>Active</span>  // NO
<span className="status status--success"><span className="dot" /> Active</span>  // YES

// ❌ Never invent CSS classes outside UI Kit
<div className="my-custom-card"> // NO — добавить в ui-kit или использовать существующий

// ❌ Never write your own CSS for status colors, buttons, cards
// All visuals come from ui-kit/

// ❌ Never import all icons
import * as Icons from '@tabler/icons-react'; // Kills tree-shaking
```

**Дополнительно в автономном режиме запрещено:**

```
расширять scope задачи самовольно                      — STOP и спросить
менять api/client.ts / app/providers.tsx / vite.config.ts без STOP — обязательная остановка
добавлять Vite entry / Twig mount без STOP             — обязательная остановка
npm install / npm uninstall без STOP                   — обязательная остановка
менять ui-kit/tokens/*.css без STOP                    — обязательная остановка
добавлять/удалять/переименовывать класс в ui-kit без STOP — обязательная остановка
добавлять компонент в react/ui-kit/ без STOP           — обязательная остановка
bump UI Kit версии без STOP                            — обязательная остановка
коммитить незакрытый этап (красный self-review)        — нельзя
merge / force-push                                     — никогда автономно
```

---

## 16. Migration: legacy → modules

`assets/react/_legacy/` — карантин для старого кода. Правила:

1. Любая правка legacy-файла = задача на миграцию в `modules/<name>/`. Не правим точечно.
2. ESLint запрещает импорт из `_legacy/` в `modules/` и `ui-kit/`. Импорт разрешён только из `entrypoints/` — чтобы старые страницы продолжали работать до миграции.
3. Миграция модуля = отдельная задача по этому workflow (Phase 0 → F1–F5 → Final).
4. После миграции — соответствующие файлы из `_legacy/` **удаляются в том же PR**. Параллельное существование двух реализаций — запрещено.
5. Цель — пустой `_legacy/`. Прогресс отслеживается: «осталось N файлов из M».

---

## 17. Закрытие этапа

В конце каждого этапа — строго по порядку:

1. Прогнать `npm run lint && npm run typecheck && npm run test && npm run check:ui-kit`. Если затронуты mount/build — также `npm run build`.
2. Пройти **Self-review checklist** (раздел 14). Любой красный пункт — этап не закрыт.
3. Сделать коммит: Conventional Commits, сообщение отражает цель этапа.
4. Сохранить **Stage Report** в `docs/tasks/<id>/stages/stage-F<N>.md`.
5. Решить по риск-классу:
    - 🟢 LOW / 🟡 MEDIUM → продолжать к следующему этапу автономно.
    - 🔴 HIGH → 🛑 STOP, ждать Владельца.

---

## 18. Закрытие задачи (Phase Final)

1. Прогнать полный набор: `npm run lint && npm run typecheck && npm run test && npm run build && npm run check:ui-kit && npm run check:uikit-react-mapping`.
2. Сверить построчно «What NOT to do» и Self-review checklist.
3. Собрать `docs/tasks/<id>/handoff.md`:
    - summary всех этапов,
    - список новых Vite entries + Twig mount points,
    - список изменений в shared-инфраструктуре (`api/client.ts`, `app/providers.tsx`, `vite.config.ts`),
    - список изменений в UI Kit (компоненты, паттерны, токены, версия bump),
    - запись в `ui-kit/CHANGELOG.md`, если затронут,
    - список новых npm-зависимостей с обоснованием,
    - размер бандла до/после,
    - риски и follow-ups, вынесенные за scope.
4. 🛑 **STOP. Final Owner review.** Merge — только после одобрения Владельцем.

---

## Приложение A. Связанные документы

- `screen-intake.md` — задача для Claude Code на анализ HTML-макета от дизайнера. Запускается на каждый новый screen.
- `ui-kit/decisions.md` — ADR дизайн-системы.
- `ui-kit/CHANGELOG.md` — журнал изменений UI Kit.
- `ui-kit/README.md` — обзор UI Kit и инструкция для дизайнера.
- `screens/README.md` — матрица реализации макетов.
- `docs/tasks/<id>/` — артефакты конкретной задачи (план, контракты, отчёты, handoff).