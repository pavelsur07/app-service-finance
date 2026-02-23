Ниже — **финальная версия v2** под твой стек: **Vite + Symfony UX React + Tabler + session-based auth + fetch** + эталонные куски кода, которые можно копировать и использовать как baseline.

---

# Стандарт разработки Frontend UI (v2) — React Islands + Tabler (Vite + Symfony UX React)

**Цель:** Быстро развивать интерактивные виджеты/дашборды, не превращая Symfony/Twig в SPA.
**Принцип:** React — только **острова** внутри Twig. Layout/навигация — Twig + Tabler.

---

## 1) Границы применимости React

**✅ React используем для:**

* интерактивных дашбордов (периоды/фильтры/виджеты),
* визуализаций и сложных таблиц,
* real-time/drag&drop/виртуализации списков,
* UI вокруг API-данных, где важны частые обновления состояния.

**❌ React не используем для:**

* базовых CRUD-страниц, простых форм и статических списков,
* страниц просмотра сущностей,
* глобального layout/sidebar/topbar.

---

## 2) Islands внутри Twig: контракт монтирования (обязательно)

### 2.1 Контейнер (Twig)

Каждый виджет монтируется в свой контейнер:

* `data-react-widget="DashboardCashFlow"`
* `data-props="...json..."` (небольшие props)
* если props большие → `<script type="application/json" data-props-for="...">...</script>`

**Правила:**

* 1 контейнер = 1 виджет.
* props должны содержать серверный контекст (например `companyId`, `tz`, `csrf`, `lockFlags`, `propsVersion`).
* React не роутит страницу (никакого SPA).

### 2.2 Props versioning

В props добавляем `propsVersion` (например `"2"`). Любой breaking change → новый `propsVersion` + отдельный PR.

---

## 3) Структура директорий (feature-first)

```text
assets/react/
├── app/
│   ├── registry.ts                 # map: widgetName -> component
│   ├── mount.tsx                   # mountAll + readProps
│   └── main.tsx                    # entry (Vite)
├── shared/
│   ├── http/
│   │   ├── client.ts               # fetch wrapper + ApiError
│   │   └── csrf.ts                 # CSRF source helpers
│   ├── ui/
│   │   ├── ErrorBoundary.tsx
│   │   └── WidgetShell.tsx         # 4 states
│   ├── hooks/
│   │   └── useAbortableQuery.ts
│   └── format/
│       ├── money.ts
│       └── date.ts
└── dashboard/
    ├── widgets/
    │   └── CashFlowWidget.tsx
    ├── api/
    │   └── cashflow.ts
    └── types/
        └── dto.ts
```

---

## 4) Vite (entry points) — без зоопарка

* Один entry на крупную зону: `assets/react/app/main.tsx`.
* Внутри entry — registry виджетов + `mountAll()`.

---

## 5) UI/стиль: Strict Tabler

* Tabler — источник истины (grid/cards/buttons/states).
* Tailwind (если включён) — только spacing/layout, **не** “рисовать” кнопки/карточки вместо Tabler.
* Типографика: `400` по умолчанию, `600` точечно.
* Кастомный CSS — по минимуму, локально.

---

## 6) API/DTO/деньги/даты

* React получает **только DTO**.
* Даты из API: `ISO-8601`.
* Деньги: `{ amount: number, currency: "RUB" }` (минимальные единицы).
* Отображение — через единые форматтеры `Intl.*`.

---

## 7) Fetch политика (единая)

### 7.1 Никакого прямого `fetch()` в виджетах

Все запросы — через `shared/http/client.ts`.

### 7.2 Abort + “последний запрос выигрывает”

Любой запрос в виджете:

* создаётся с `AbortController`
* отменяется при размонтировании и смене фильтра/периода
* предыдущий запрос abort’им

### 7.3 Единый UX по статусам

* `401`: “Сессия истекла — обновите страницу”
* `403`: “Нет доступа”
* `409`: “Период закрыт / конфликт”
* `422`: ошибки валидации (для форм внутри виджета)
* network/`5xx`: “Сервис недоступен” + Retry

---

## 8) Обязательные состояния виджета

Каждый сетевой виджет обязан иметь:

1. `loading`
2. `empty`
3. `error` (с Retry)
4. `success`

Большие списки: виртуализация/пагинация/lazy.

---

## 9) Надёжность: ErrorBoundary на каждый остров

Падение одного виджета не ломает страницу.

---

## 10) Безопасность

* Источник истины по правам/lock — сервер.
* UI может скрывать кнопки, но сервер валидирует всегда.
* Мутации включают CSRF (session-based).

---

## 11) DoD (v2)

* [ ] Tabler UI соблюдён
* [ ] 4 состояния реализованы через `WidgetShell`
* [ ] запросы только через `httpClient`
* [ ] abort/race учтены
* [ ] 401/403/409 отображаются user-friendly
* [ ] smoke: happy + error
* [ ] атомарный PR

---

# Эталонные коды (копируй-вставляй)

## A) Twig контейнер (пример)

```twig
{# dashboard.html.twig #}
<div
  data-react-widget="DashboardCashFlow"
  data-props='{{ {
    propsVersion: "2",
    companyId: app.user ? app.user.activeCompanyId : null,
    tz: app.user ? app.user.timezone : "Europe/Moscow",
    locale: app.request.locale|default("ru"),
    csrf: csrf_token("api")  # если используете одинаковую "id" для API мутаций
  }|json_encode|e("html_attr") }}'
></div>
```

> Если props большие — вместо `data-props` положи JSON в `<script type="application/json" data-props-for="DashboardCashFlow">...</script>`.

---

## B) `assets/react/app/main.tsx` (Vite entry)

```tsx
import { mountAll } from "./mount";

document.addEventListener("DOMContentLoaded", () => {
  mountAll(document);
});
```

---

## C) `assets/react/app/registry.ts` (реестр виджетов)

```tsx
import type { ComponentType } from "react";
import { CashFlowWidget } from "../dashboard/widgets/CashFlowWidget";

export type WidgetComponent = ComponentType<any>;

export const widgetRegistry: Record<string, WidgetComponent> = {
  DashboardCashFlow: CashFlowWidget,
  // добавляй новые виджеты сюда
};
```

---

## D) `assets/react/app/mount.tsx` (mountAll + props)

```tsx
import React from "react";
import { createRoot } from "react-dom/client";
import { widgetRegistry } from "./registry";
import { ErrorBoundary } from "../shared/ui/ErrorBoundary";

type MountEl = HTMLElement;

function readProps(el: MountEl): any {
  const raw = el.dataset.props;
  if (raw && raw.trim().length > 0) {
    try {
      return JSON.parse(raw);
    } catch {
      return { propsParseError: true, raw };
    }
  }

  // optional: large props in <script type="application/json" data-props-for="WidgetName">
  const widgetName = el.dataset.reactWidget;
  if (widgetName) {
    const script = document.querySelector<HTMLScriptElement>(
      `script[type="application/json"][data-props-for="${widgetName}"]`
    );
    if (script?.textContent) {
      try {
        return JSON.parse(script.textContent);
      } catch {
        return { propsParseError: true };
      }
    }
  }

  return {};
}

export function mountAll(root: ParentNode): void {
  const nodes = root.querySelectorAll<MountEl>("[data-react-widget]");
  nodes.forEach((el) => {
    const widgetName = el.dataset.reactWidget!;
    const Widget = widgetRegistry[widgetName];

    if (!Widget) {
      // fail-safe: do not break page
      // eslint-disable-next-line no-console
      console.warn(`[react-islands] Unknown widget: ${widgetName}`);
      return;
    }

    const props = readProps(el);

    const reactRoot = createRoot(el);
    reactRoot.render(
      <ErrorBoundary widgetName={widgetName}>
        <Widget {...props} />
      </ErrorBoundary>
    );
  });
}
```

---

## E) `assets/react/shared/ui/ErrorBoundary.tsx`

```tsx
import React from "react";

type Props = {
  widgetName: string;
  children: React.ReactNode;
};

type State = { hasError: boolean; error?: Error };

export class ErrorBoundary extends React.Component<Props, State> {
  state: State = { hasError: false };

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error };
  }

  componentDidCatch(error: Error) {
    // Можно отправить на сервер лог (опционально)
    // console.error(`[${this.props.widgetName}]`, error);
  }

  render() {
    if (this.state.hasError) {
      return (
        <div className="alert alert-danger">
          <div className="d-flex align-items-center justify-content-between">
            <div>
              <div className="fw-semibold">Ошибка виджета</div>
              <div className="text-muted">
                Попробуйте обновить страницу. Если повторяется — сообщите в поддержку.
              </div>
            </div>
            <button className="btn btn-outline-light" onClick={() => location.reload()}>
              Обновить
            </button>
          </div>
        </div>
      );
    }

    return this.props.children;
  }
}
```

---

## F) `assets/react/shared/ui/WidgetShell.tsx` (4 состояния на Tabler)

```tsx
import React from "react";

type Props = {
  title?: string;
  subtitle?: string;

  isLoading: boolean;
  isEmpty: boolean;
  error: string | null;

  onRetry?: () => void;

  headerRight?: React.ReactNode;
  children: React.ReactNode;
};

export function WidgetShell({
  title,
  subtitle,
  isLoading,
  isEmpty,
  error,
  onRetry,
  headerRight,
  children,
}: Props) {
  return (
    <div className="card">
      {(title || headerRight) && (
        <div className="card-header">
          <div className="card-title">
            <div className="d-flex align-items-center justify-content-between w-100">
              <div>
                {title && <div className="fw-semibold">{title}</div>}
                {subtitle && <div className="text-muted small">{subtitle}</div>}
              </div>
              {headerRight}
            </div>
          </div>
        </div>
      )}

      <div className="card-body">
        {isLoading && (
          <div className="d-flex align-items-center gap-2">
            <div className="spinner-border" role="status" aria-label="Loading" />
            <div className="text-muted">Загрузка…</div>
          </div>
        )}

        {!isLoading && error && (
          <div className="alert alert-danger">
            <div className="d-flex align-items-center justify-content-between">
              <div>{error}</div>
              {onRetry && (
                <button className="btn btn-outline-light" onClick={onRetry}>
                  Повторить
                </button>
              )}
            </div>
          </div>
        )}

        {!isLoading && !error && isEmpty && (
          <div className="empty">
            <div className="empty-header">—</div>
            <p className="empty-title">Нет данных</p>
            <p className="empty-subtitle text-muted">Попробуйте изменить период или фильтры.</p>
          </div>
        )}

        {!isLoading && !error && !isEmpty && children}
      </div>
    </div>
  );
}
```

---

## G) CSRF helper: `assets/react/shared/http/csrf.ts`

Вариант 1 (рекомендую для островов): **передавать CSRF в props** (`csrf`).

Вариант 2 (универсально): читать из `<meta>`:

```ts
export function readCsrfFromMeta(metaName = "csrf-token"): string | null {
  const el = document.querySelector<HTMLMetaElement>(`meta[name="${metaName}"]`);
  return el?.content || null;
}
```

---

## H) Fetch wrapper: `assets/react/shared/http/client.ts`

```ts
export type Money = { amount: number; currency: string };

export type ApiErrorKind =
  | "unauthorized"
  | "forbidden"
  | "conflict"
  | "validation"
  | "network"
  | "server"
  | "unknown";

export class ApiError extends Error {
  constructor(
    message: string,
    public readonly kind: ApiErrorKind,
    public readonly status?: number,
    public readonly details?: unknown
  ) {
    super(message);
  }
}

type RequestOptions = {
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  query?: Record<string, string | number | boolean | null | undefined>;
  body?: unknown;
  signal?: AbortSignal;
  csrfToken?: string | null; // для мутаций
};

function buildUrl(url: string, query?: RequestOptions["query"]): string {
  if (!query) return url;
  const u = new URL(url, window.location.origin);
  Object.entries(query).forEach(([k, v]) => {
    if (v === null || v === undefined) return;
    u.searchParams.set(k, String(v));
  });
  return u.toString();
}

async function parseJsonSafe(res: Response): Promise<any> {
  const text = await res.text();
  if (!text) return null;
  try {
    return JSON.parse(text);
  } catch {
    return { raw: text };
  }
}

export async function httpJson<T>(url: string, opts: RequestOptions = {}): Promise<T> {
  const method = opts.method ?? "GET";
  const fullUrl = buildUrl(url, opts.query);

  const headers: Record<string, string> = {
    Accept: "application/json",
  };

  let body: string | undefined;
  if (opts.body !== undefined) {
    headers["Content-Type"] = "application/json";
    body = JSON.stringify(opts.body);
  }

  // CSRF: только для мутаций (POST/PUT/PATCH/DELETE)
  const isMutation = method !== "GET";
  if (isMutation && opts.csrfToken) {
    headers["X-CSRF-Token"] = opts.csrfToken;
  }

  let res: Response;
  try {
    res = await fetch(fullUrl, {
      method,
      headers,
      body,
      credentials: "same-origin",
      signal: opts.signal,
    });
  } catch (e: any) {
    if (e?.name === "AbortError") throw e;
    throw new ApiError("Сеть недоступна. Проверьте подключение и повторите.", "network");
  }

  if (res.ok) {
    // 204 No Content
    if (res.status === 204) return null as unknown as T;
    return (await parseJsonSafe(res)) as T;
  }

  const payload = await parseJsonSafe(res);

  // Стандартизируем сообщения (можно адаптировать под ваш формат ошибок Symfony)
  switch (res.status) {
    case 401:
      throw new ApiError("Сессия истекла. Обновите страницу.", "unauthorized", 401, payload);
    case 403:
      throw new ApiError("Недостаточно прав для этого действия.", "forbidden", 403, payload);
    case 409:
      throw new ApiError("Конфликт изменений или период закрыт.", "conflict", 409, payload);
    case 422:
      throw new ApiError("Проверьте корректность данных.", "validation", 422, payload);
    default:
      if (res.status >= 500) {
        throw new ApiError("Сервис временно недоступен. Повторите позже.", "server", res.status, payload);
      }
      throw new ApiError("Ошибка запроса.", "unknown", res.status, payload);
  }
}
```

---

## I) Abortable hook: `assets/react/shared/hooks/useAbortableQuery.ts`

```ts
import { useCallback, useEffect, useRef, useState } from "react";
import { ApiError, httpJson } from "../http/client";

type State<T> = {
  isLoading: boolean;
  data: T | null;
  error: string | null;
};

type RunOptions = {
  url: string;
  query?: Record<string, any>;
  csrfToken?: string | null;
};

export function useAbortableQuery<T>() {
  const abortRef = useRef<AbortController | null>(null);

  const [state, setState] = useState<State<T>>({
    isLoading: false,
    data: null,
    error: null,
  });

  const run = useCallback(async (opts: RunOptions) => {
    // abort previous
    abortRef.current?.abort();
    const ac = new AbortController();
    abortRef.current = ac;

    setState((s) => ({ ...s, isLoading: true, error: null }));

    try {
      const data = await httpJson<T>(opts.url, {
        method: "GET",
        query: opts.query,
        signal: ac.signal,
        csrfToken: opts.csrfToken ?? null,
      });

      setState({ isLoading: false, data, error: null });
      return data;
    } catch (e: any) {
      if (e?.name === "AbortError") return null;

      const msg =
        e instanceof ApiError ? e.message : "Не удалось загрузить данные. Повторите попытку.";
      setState((s) => ({ ...s, isLoading: false, error: msg }));
      return null;
    }
  }, []);

  useEffect(() => {
    return () => abortRef.current?.abort();
  }, []);

  return { ...state, run };
}
```

---

## J) Форматтеры

### `assets/react/shared/format/money.ts`

```ts
import type { Money } from "../http/client";

export function formatMoney(m: Money, locale = "ru-RU"): string {
  return new Intl.NumberFormat(locale, {
    style: "currency",
    currency: m.currency,
    currencyDisplay: "symbol",
    maximumFractionDigits: 2,
  }).format(m.amount / 100);
}
```

### `assets/react/shared/format/date.ts`

```ts
export function formatDate(iso: string, locale = "ru-RU", timeZone?: string): string {
  const d = new Date(iso);
  return new Intl.DateTimeFormat(locale, {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    timeZone,
  }).format(d);
}
```

---

## K) Пример API слоя: `assets/react/dashboard/api/cashflow.ts`

```ts
import { httpJson, type Money } from "../../shared/http/client";

export type CashFlowDto = {
  period: { from: string; to: string };
  inflow: Money;
  outflow: Money;
  net: Money;
};

export async function fetchCashFlow(params: {
  from: string;
  to: string;
  csrfToken?: string | null;
  signal?: AbortSignal;
}): Promise<CashFlowDto> {
  return httpJson<CashFlowDto>("/api/dashboard/v1/cashflow", {
    method: "GET",
    query: { from: params.from, to: params.to },
    csrfToken: params.csrfToken ?? null,
    signal: params.signal,
  });
}
```

---

## L) Пример виджета: `assets/react/dashboard/widgets/CashFlowWidget.tsx`

```tsx
import React, { useCallback, useEffect, useMemo, useState } from "react";
import { WidgetShell } from "../../shared/ui/WidgetShell";
import { useAbortableQuery } from "../../shared/hooks/useAbortableQuery";
import { formatMoney } from "../../shared/format/money";
import type { Money } from "../../shared/http/client";

type Props = {
  propsVersion?: string;
  companyId?: string | null;
  tz?: string;
  locale?: string;
  csrf?: string; // CSRF token from server
};

type CashFlowDto = {
  inflow: Money;
  outflow: Money;
  net: Money;
};

function isEmptyCashflow(dto: CashFlowDto | null): boolean {
  if (!dto) return true;
  return dto.inflow.amount === 0 && dto.outflow.amount === 0 && dto.net.amount === 0;
}

export function CashFlowWidget(props: Props) {
  const locale = props.locale ?? "ru-RU";
  const csrf = props.csrf ?? null;

  // пример локального периода (в реальности — возьмёшь из props или общего фильтра страницы)
  const [period, setPeriod] = useState(() => {
    const to = new Date();
    const from = new Date();
    from.setDate(to.getDate() - 30);
    const toIso = to.toISOString().slice(0, 10);
    const fromIso = from.toISOString().slice(0, 10);
    return { from: fromIso, to: toIso };
  });

  const { isLoading, data, error, run } = useAbortableQuery<CashFlowDto>();

  const load = useCallback(() => {
    return run({
      url: "/api/dashboard/v1/cashflow",
      query: { from: period.from, to: period.to },
      csrfToken: csrf,
    });
  }, [run, period.from, period.to, csrf]);

  useEffect(() => {
    void load();
  }, [load]);

  const empty = useMemo(() => !isLoading && !error && isEmptyCashflow(data), [isLoading, error, data]);

  const headerRight = (
    <div className="d-flex gap-2">
      <button
        className="btn btn-outline-primary btn-sm"
        onClick={() => {
          // пример: быстро “месяц”
          const to = new Date();
          const from = new Date();
          from.setDate(to.getDate() - 30);
          setPeriod({
            from: from.toISOString().slice(0, 10),
            to: to.toISOString().slice(0, 10),
          });
        }}
      >
        30 дней
      </button>

      <button className="btn btn-outline-primary btn-sm" onClick={() => void load()}>
        Обновить
      </button>
    </div>
  );

  return (
    <WidgetShell
      title="Cash Flow"
      subtitle={`${period.from} — ${period.to}`}
      headerRight={headerRight}
      isLoading={isLoading}
      isEmpty={empty}
      error={error}
      onRetry={() => void load()}
    >
      {data && (
        <div className="row">
          <div className="col-md-4">
            <div className="text-muted">Поступления</div>
            <div className="h3 m-0">{formatMoney(data.inflow, locale)}</div>
          </div>
          <div className="col-md-4">
            <div className="text-muted">Списания</div>
            <div className="h3 m-0">{formatMoney(data.outflow, locale)}</div>
          </div>
          <div className="col-md-4">
            <div className="text-muted">Итог</div>
            <div className="h3 m-0">{formatMoney(data.net, locale)}</div>
          </div>
        </div>
      )}
    </WidgetShell>
  );
}
```

---

# Минимальные рекомендации по Vite + Symfony UX React (без лишнего)

* Подключай `assets/react/app/main.tsx` как Vite entry (как у тебя принято).
* В Twig-шаблонах подключай Vite assets (как в твоём бандле/интеграции).
* Контейнеры с `data-react-widget` можно размещать точечно по странице (Tabler cards/grid вокруг — Twig).

---
