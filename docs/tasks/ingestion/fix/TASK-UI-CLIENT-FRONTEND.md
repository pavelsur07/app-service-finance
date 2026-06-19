# TASK-UI-CLIENT-FRONTEND — Клиентский UI Ingestion (Frontend часть)

## 0. Сводка

- **Бизнес-цель.** Реализовать 4 React-экрана для клиентского UI Ingestion (Покрытие, Сверка, Проблемы, Финансовая сводка) поверх готового backend API. Дать клиенту визуальный контроль качества новой загрузки Ozon.
- **Модуль.** Frontend в `assets/react/features/ingestion-verification/` + entrypoints в `assets/react/entrypoints/` + Twig-страницы в `templates/ingestion/verification/`.
- **Тип.** feature (frontend).
- **Ветка.** `feature/ingestion-ui-client-frontend`.
- **Подзадачи.** B1 Shared-компоненты · B2 4 widget feature-slice · B3 4 entry-script + регистрация в Vite · B4 4 Twig-страницы · B5 PageController · B6 Multi-shop с localStorage · B7 Маршруты · B8 E2E проверка.
- **Зависимость:** требуется завершённая задача `TASK-UI-CLIENT-BACKEND` со зелёным `schema.d.ts`.
- **Требует миграции БД.** Нет.
- **Меняет публичный API.** Нет.

---

## 1. Контекст и границы

### 1.1 Текущее состояние

- Backend готов: 4 эндпоинта под `/api/ingestion/verification/*`, OpenAPI, `schema.d.ts` закоммичен.
- В проекте существует `CLAUDE.frontend.md` с архитектурой feature-slice (Widget/View/hook/types).
- Используется React + TanStack Query + Vite.
- Существует страница P&L legacy — не трогаем.

### 1.2 Желаемое состояние

- 4 React-экрана как островки внутри Twig-страниц.
- Типы из `schema.d.ts` (strict TS, без сырого fetch).
- Multi-shop фильтр на каждом экране, сохранение выбора в localStorage.
- Loading / empty / error состояния на каждом widget.
- 4 новых Twig-маршрута под `/ingestion/verification/*`.
- Существующая страница P&L legacy не затрагивается.

### 1.3 In scope

- React feature-slice: 4 widget (Widget/View/hook/types).
- Shared-компоненты: shop-selector, period-picker, money-cell, delta-cell, status-badge, empty-state, loading-state.
- 4 entry-script в `assets/react/entrypoints/`.
- Явная регистрация 4 entry-script в `site/vite.config.js` (`build.rollupOptions.input`), чтобы `entrypoints.json` содержал ключи для Twig.
- 4 Twig-страницы.
- 4 PageController (`__invoke`, рендер Twig).
- Маршруты Twig в `config/routes.yaml`.
- Multi-shop UX с localStorage (`ingestion.selected_shop`).
- Обработка ошибок: показ `error.message` из ответа API + кнопка «Повторить».

### 1.4 Out of scope

- Backend (контроллеры, Query, эндпоинты) — задача `TASK-UI-CLIENT-BACKEND`.
- Экспорт XLSX.
- Admin раздел.
- Изменение существующей P&L страницы.
- Графики/чарты — только таблицы и хитмап.

### 1.5 Допущения

- Допущение: `schema.d.ts` корректно отражает API. При несоответствии — фиксится backend.
- Допущение: TanStack Query уже настроен в проекте (QueryClient, провайдер).
- Не допущение: новые entry-script'ы нужно явно добавить в `site/vite.config.js` → `build.rollupOptions.input`; проект не подхватывает `assets/react/entrypoints/` автоматически.

---

## 2. Доменная модель

N/A — frontend не имеет своих Entity. Типы данных импортируются из `schema.d.ts`.

---

## 3. Слой доступа к данным

### 3.1 API hooks

Все hooks — `assets/react/features/ingestion-verification/api/`.

#### `ingestion-verification.api.ts`

TanStack Query hooks, типы из `schema.d.ts`.

| Hook | Endpoint | Возврат |
|---|---|---|
| `useCoverageData(params)` | `GET /api/ingestion/verification/coverage` | `{cells, shops}` |
| `useReconciliationData(params)` | `GET /api/ingestion/verification/reconciliation` | `{summary, by_type}` |
| `useIssuesData(params)` | `GET /api/ingestion/verification/issues` | `{items, meta}` |
| `useFinancialSummaryData(params)` | `GET /api/ingestion/verification/financial-summary` | `{by_month, by_category}` |

Все hooks:
- Используют `staleTime: 30000` (30 сек).
- При ошибке возвращают `error` с `message` из `error.message` ответа API.
- Запрос автоматически отключается, если обязательные параметры не заданы (`enabled: !!period`).

### 3.2 Query params

N/A.

### 3.3 Индексы

N/A.

---

## 4. Слой приложения (компоненты)

### 4.1 Shared-компоненты

Папка: `assets/react/features/ingestion-verification/components/shared/`.

#### `ShopSelector`

`shop-selector.tsx`. Селектор магазина.

Props:
- `shops: ShopOption[]` — список магазинов из API.
- `value: string | null` — текущий shopRef (null = все магазины).
- `onChange: (shopRef: string | null) => void`.

Поведение:
- Загружает выбор из `localStorage.getItem('ingestion.selected_shop')` при монтировании.
- Сохраняет в localStorage при изменении.
- Опция «Все магазины» (value = null).

#### `PeriodPicker`

`period-picker.tsx`. Выбор периода.

Props:
- `mode: 'month' | 'range'` — один месяц или диапазон.
- `value: {year: number, month: number} | {yearFrom, monthFrom, yearTo, monthTo}`.
- `onChange: (value) => void`.

#### `MoneyCell`

`money-cell.tsx`. Форматирование минорных единиц.

Props:
- `amountMinor: number`.
- `currency: string`.

Возврат: «1 234,56 ₽» (форматирование через `Intl.NumberFormat`).

#### `DeltaCell`

`delta-cell.tsx`. Подсветка разницы.

Props:
- `deltaMinor: number | null`.
- `thresholdMinor: number`.
- `currency: string`.

Поведение:
- Если `delta === null` → «—» серым.
- Если `|delta| <= threshold` → зелёный.
- Если `|delta| > threshold` → красный.

#### `StatusBadge`

`status-badge.tsx`. Бейдж статуса.

Props:
- `status: 'success' | 'warning' | 'error' | 'pending'`.
- `label: string`.

#### `EmptyState`

`empty-state.tsx`. Пустое состояние.

Props:
- `message: string` (по умолчанию «Данных нет за выбранный период»).

#### `LoadingState`

`loading-state.tsx`. Состояние загрузки.

Props:
- `message?: string`.

Скелетон или спиннер.

#### `ErrorState`

`error-state.tsx`. Состояние ошибки.

Props:
- `error: {code: string, message: string}`.
- `onRetry: () => void`.

Показывает `error.message`, кнопка «Повторить» зовёт `onRetry`.

### 4.2 Widget'ы

Структура каждого widget по `CLAUDE.frontend.md`:
- `*.widget.tsx` — data-aware: использует hook, передаёт данные в View.
- `*.view.tsx` — dumb: только props, без data fetching.
- `use-*.ts` — TanStack Query hook.
- `types.ts` — типы из `schema.d.ts`.

#### `coverage-heatmap`

Папка: `widgets/coverage-heatmap/`.

`coverage-heatmap.widget.tsx`:
- Хранит state: `from`, `to`, `shopRef`.
- Зовёт `useCoverageData({from, to, shopRef})`.
- Передаёт данные в `CoverageHeatmapView`.
- Обрабатывает loading/empty/error.

`coverage-heatmap.view.tsx`:
- Props: `cells`, `shops`, `selectedShop`, `from`, `to`, `onShopChange`, `onPeriodChange`.
- Рендерит:
  - `ShopSelector` сверху.
  - `PeriodPicker` (mode='range').
  - Хитмап: ось X — даты, ось Y — resourceType. Ячейка: зелёная если `rawCount > 0 && issueCount === 0`, жёлтая если `issueCount > 0`, серая если нет данных.
  - При клике на ячейку — tooltip с `rawCount`, `txCount`, `issueCount`, `lastFetchedAt`.
  - Счётчики вверху: «X дней покрыто», «Y проблем».

#### `reconciliation-summary`

Папка: `widgets/reconciliation-summary/`.

`reconciliation-summary.widget.tsx`:
- State: `shopRef` (обязательный), `year`, `month`.
- Зовёт `useReconciliationData({shopRef, year, month})`.

`reconciliation-summary.view.tsx`:
- Props: `summary`, `by_type`, `selectedShop`, `year`, `month`, `onShopChange`, `onPeriodChange`.
- Рендерит:
  - `ShopSelector` (обязательный, без «Все»).
  - `PeriodPicker` (mode='month').
  - Главная карточка: «Сходится» / «Расхождение на X руб» — на основе `canon_vs_ozon_delta_minor` и `threshold_minor` через `DeltaCell`.
  - Таблица по типам: TransactionType label, `canon_amount_minor` через `MoneyCell`, `tx_count`.
  - Если `ozon_control_total_minor === null` — баннер «Контрольная сумма Ozon недоступна для этого периода».
  - Дата `recomputed_at`.

#### `issues-list`

Папка: `widgets/issues-list/`.

`issues-list.widget.tsx`:
- State: `shopRef`, `year`, `month`, `page`, `limit=50`.
- Зовёт `useIssuesData(params)`.

`issues-list.view.tsx`:
- Props: `items`, `meta`, `selectedShop`, `year`, `month`, `page`, `onShopChange`, `onPeriodChange`, `onPageChange`.
- Рендерит:
  - `ShopSelector`.
  - `PeriodPicker` (mode='month', optional).
  - Счётчик: «X открытых проблем».
  - Таблица: дата, тип (badge), `human_description`. Только человекочитаемые поля.
  - Пагинация: prev/next, текущая страница из `meta`.

#### `financial-summary`

Папка: `widgets/financial-summary/`.

`financial-summary.widget.tsx`:
- State: `shopRef`, `yearFrom`, `monthFrom`, `yearTo`, `monthTo`.
- Зовёт `useFinancialSummaryData(params)`.

`financial-summary.view.tsx`:
- Props: `by_month`, `by_category`, `selectedShop`, `period`, `onShopChange`, `onPeriodChange`.
- Рендерит:
  - `ShopSelector`.
  - `PeriodPicker` (mode='range').
  - Таблица «По месяцам»: год-месяц, доход (`MoneyCell`), расход, прибыль.
  - Таблица «По категориям» за последний месяц диапазона: категория, направление (badge), сумма.

### 4.3 Entry-scripts

Папка: `assets/react/entrypoints/`.

Каждый монтирует один widget в `<div id="root">`:
- `ingestion-verification-coverage.tsx`
- `ingestion-verification-reconciliation.tsx`
- `ingestion-verification-issues.tsx`
- `ingestion-verification-financial-summary.tsx`

После создания файлов добавить их в `site/vite.config.js`:

```js
rollupOptions: {
    input: {
        ingestion_verification_coverage: "./assets/react/entrypoints/ingestion-verification-coverage.tsx",
        ingestion_verification_reconciliation: "./assets/react/entrypoints/ingestion-verification-reconciliation.tsx",
        ingestion_verification_issues: "./assets/react/entrypoints/ingestion-verification-issues.tsx",
        ingestion_verification_financial_summary: "./assets/react/entrypoints/ingestion-verification-financial-summary.tsx",
    },
}
```

Twig должен использовать те же ключи: `vite_entry_script_tags('ingestion_verification_coverage')`, `vite_entry_script_tags('ingestion_verification_reconciliation')`, `vite_entry_script_tags('ingestion_verification_issues')`, `vite_entry_script_tags('ingestion_verification_financial_summary')`.

### 4.4 Twig-страницы

Папка: `templates/ingestion/verification/`.

Каждая содержит:
- Заголовок страницы (название экрана).
- Mount point: `<div id="root"></div>`.
- Подключение entry-script через `vite_entry_script_tags(...)` с ключами из `site/vite.config.js`.

Файлы:
- `coverage.html.twig`
- `reconciliation.html.twig`
- `issues.html.twig`
- `financial-summary.html.twig`

### 4.5 PageController

Папка: `src/Ingestion/Controller/Page/`. Все `final class`, `__invoke`, без бизнес-логики.

- `CoveragePageController` → рендер `coverage.html.twig`.
- `ReconciliationPageController` → `reconciliation.html.twig`.
- `IssuesPageController` → `issues.html.twig`.
- `FinancialSummaryPageController` → `financial-summary.html.twig`.

Маршруты (через `#[Route]` атрибут):
- `GET /ingestion/verification/coverage`
- `GET /ingestion/verification/reconciliation`
- `GET /ingestion/verification/issues`
- `GET /ingestion/verification/financial-summary`

---

## 5. Асинхронность (Messenger)

N/A.

---

## 6. Обработка ошибок

Frontend обрабатывает 422 ошибки от backend:

```json
{ "error": { "code": "invalid_period_range", "message": "Некорректный диапазон периода" } }
```

В каждом widget'е при ошибке отображается `ErrorState` с `error.message` и кнопкой «Повторить».

Network ошибки (500, timeout) — отображаются как «Не удалось загрузить данные, попробуйте позже» + кнопка «Повторить».

---

## 7. HTTP API (Controller)

PageController (см. §4.5) — без API логики, только рендер Twig.

---

## 8. Разбивка на подзадачи

| Этап | Что входит | Зависит от | Риск | Тесты |
|---|---|---|---|---|
| B1 | Shared-компоненты (`shop-selector`, `period-picker`, `money-cell`, `delta-cell`, `status-badge`, `empty-state`, `loading-state`, `error-state`) | `schema.d.ts` готов | 🟡 | TS strict |
| B2 | API hooks (`ingestion-verification.api.ts`) | B1 | 🟡 | unit на параметры |
| B3 | `coverage-heatmap` widget + entry-script | B2 | 🟡 | TS strict |
| B4 | `reconciliation-summary` widget + entry-script | B2 | 🟡 | TS strict |
| B5 | `issues-list` widget + entry-script | B2 | 🟡 | TS strict + пагинация |
| B6 | `financial-summary` widget + entry-script | B2 | 🟡 | TS strict |
| B7 | 4 Twig-страницы + 4 PageController + маршруты | B3-B6 | 🟢 | functional: страницы открываются |
| B8 | Multi-shop localStorage интеграция | B1 | 🟢 | E2E если есть |
| B9 | E2E проверка всех 4 страниц под авторизованным пользователем | B7 | 🟡 | manual или Playwright |
| B10 | `ARCHITECTURE.md` + раздел про feature-slice | все | 🟢 | — |

---

## 9. Ограничения и запреты

- Не использовать сырой `fetch` — только TanStack Query.
- Не делать своих типов поверх API — только из `schema.d.ts`.
- Не показывать клиенту технические поля (`operation_group_id`, `raw_record_id`, `details JSONB`).
- Не модифицировать существующую страницу P&L legacy.
- Не добавлять глобальный state — каждый widget управляет своим состоянием.
- Strict TS: `noImplicitAny`, `strictNullChecks`.
- Performance: дебаунс при изменении периода (500ms) перед инвалидацией query.

---

## 10. Критерии приёмки

Функциональные:
- [ ] Все 4 страницы открываются под авторизованным пользователем.
- [ ] Multi-shop: фильтр работает, выбор сохраняется между сессиями.
- [ ] Пустые состояния на каждом экране.
- [ ] Состояния загрузки на каждом экране.
- [ ] Состояния ошибки с кнопкой «Повторить».
- [ ] Денежные суммы форматируются корректно (с разделителями, валютой).
- [ ] DeltaCell подсвечивает расхождения > threshold красным.
- [ ] Технические UUID и JSONB не отображаются в UI.
- [ ] Пагинация Issues работает.
- [ ] При отсутствии `ozon_control_total_minor` показывается соответствующий баннер.

Технические:
- [ ] TS strict зелёный.
- [ ] Все типы из `schema.d.ts` (нет ручных интерфейсов поверх API-ответов).
- [ ] Vite build зелёный.
- [ ] Functional тест: каждая страница возвращает 200 для авторизованного, 302/401 для неавторизованного.
- [ ] `make site-cs-check` для PHP-файлов (PageController) зелёный.
- [ ] `ARCHITECTURE.md` обновлён.

---

## 11. План отката

- Удалить routes `/ingestion/verification/*` из `config/routes.yaml`.
- Удалить entry-scripts из `site/vite.config.js`.
- Удалить feature-slice папку.
- Backend (отдельная задача) продолжит работать — API эндпоинты остаются.

---

## 12. Чек-лист качества ТЗ

- [x] Структура feature-slice по `CLAUDE.frontend.md`.
- [x] Все hooks через TanStack Query, не fetch.
- [x] Типы из `schema.d.ts`, не вручную.
- [x] Multi-shop UX с localStorage явно описан.
- [x] Состояния: loading, empty, error на каждом widget.
- [x] Shared-компоненты с props.
- [x] 4 entry-script с конкретными путями.
- [x] 4 Twig-страницы + 4 PageController.
- [x] Маршруты под `/ingestion/verification/*`.
- [x] Запрет на технические поля в UI.
- [x] Out of scope (XLSX, admin, legacy P&L).
- [x] Backend в зависимости — не дублируется здесь.
