# CLAUDE.md — Frontend UI Rules for Symfony + Twig + React + Vite

This file defines coding standards, patterns, and architecture rules for all React TypeScript UI development in this project. Follow these rules strictly on every task.

---

## Stack

- **Backend**: Symfony (PHP), Twig templates
- **Frontend**: React 18 + TypeScript (strict), Vite
- **UI Kit**: Tabler UI (React components + Tabler CSS)
- **Data fetching**: TanStack Query (React Query v5)
- **Forms**: React Hook Form + Zod
- **Styling**: Tabler CSS variables + CSS Modules for custom overrides
- **Icons**: `@tabler/icons-react`
- **HTTP**: Native `fetch` via centralized `apiClient.ts`

---

## Project Structure

```
assets/
├── react/
│   ├── components/          # Reusable UI primitives
│   │   ├── ui/              # Atoms: Button, Input, Modal, Badge
│   │   └── shared/          # Molecules: ProductCard, Pagination
│   ├── features/            # Business-domain slices
│   │   └── {feature}/
│   │       ├── {Feature}Widget.tsx   # Smart container (data-aware)
│   │       ├── {Feature}View.tsx     # Dumb presenter (UI only)
│   │       ├── use{Feature}.ts       # Business logic hook
│   │       └── {feature}.types.ts   # Feature-local types
│   ├── hooks/               # Shared custom hooks
│   ├── services/            # API client, external integrations
│   ├── types/               # Global TypeScript types
│   ├── utils/               # Pure helper functions
│   └── entrypoints/         # One mount file per Twig page/widget
│       ├── cart.tsx
│       └── product-configurator.tsx
vite.config.ts
tsconfig.json
```

**Rules:**
- One component per file. Filename = component name (PascalCase).
- Feature folder contains everything related to that domain.
- Never import from `features/X` inside `features/Y` — use `components/shared` instead.
- `entrypoints/` files only mount React into DOM; zero business logic there.

---

## TypeScript Rules

**tsconfig.json must include:**
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

**Forbidden:**
- `any` — use `unknown` and narrow with type guards
- Non-null assertion `!` — use optional chaining `?.` or explicit checks
- `@ts-ignore` — fix the type, don't suppress it
- Inline type definitions in JSX props — define `interface` above the component

**Required:**
```tsx
// ✅ Props via interface
interface CartItemProps {
  id: number;
  title: string;
  quantity: number;
  onRemove: (id: number) => void;
}

// ✅ Explicit return type on hooks
function useCart(): { items: CartItem[]; total: number; addItem: (id: number) => void } { ... }

// ✅ API response shapes typed separately
interface ApiResponse<T> {
  data: T;
  meta?: { total: number; page: number; perPage: number };
}
```

---

## Vite Configuration

```ts
// vite.config.ts
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'public/build',
    manifest: true,           // Required for Symfony asset() helper
    rollupOptions: {
      input: {
        cart: 'assets/react/entrypoints/cart.tsx',
        'product-configurator': 'assets/react/entrypoints/product-configurator.tsx',
        // Add one entry per page/widget
      },
    },
  },
  resolve: {
    alias: { '@': path.resolve(__dirname, 'assets/react') },
  },
});
```

**Rules:**
- One Vite entry per widget/page — load only what the page needs.
- Always enable `manifest: true` so Symfony can reference hashed filenames.
- Use `@` alias for all internal imports (`@/components/ui/Button`).

---

## Tabler UI

### Installation & Setup

```bash
npm install @tabler/core @tabler/icons-react
```

```ts
// assets/react/entrypoints/cart.tsx — import Tabler CSS once per entrypoint
import '@tabler/core/dist/css/tabler.min.css';
```

Alternatively, import Tabler CSS globally in Twig base layout (preferred — avoids duplicate CSS per entrypoint):

```twig
{# templates/base.html.twig #}
<link rel="stylesheet" href="{{ vite_asset('assets/tabler.css') }}">
```

```ts
// assets/tabler.css (global entry, imported in vite.config.ts)
@import '@tabler/core/dist/css/tabler.min.css';
```

---

### Using Tabler React Components

Tabler provides ready-made React components. Always import from `@tabler/core/dist/js/tabler-react.esm.js` or the package root:

```tsx
import { Card, Button, Badge, Alert, Table, Spinner } from '@tabler/core/react';
import { IconShoppingCart, IconTrash, IconCheck } from '@tabler/icons-react';
```

> **Note**: As of Tabler v1.x, React components are in `@tabler/core`. Check installed version — if using Tabler React separately, import from `@tabler/react`.

---

### Component Usage Patterns

#### Cards (primary layout unit)

```tsx
// ✅ Standard content card
const ProductCard: React.FC<ProductCardProps> = ({ title, price, status }) => (
  <div className="card">
    <div className="card-header">
      <h3 className="card-title">{title}</h3>
      <div className="card-options">
        <Badge color={status === 'active' ? 'green' : 'red'}>{status}</Badge>
      </div>
    </div>
    <div className="card-body">
      <p className="text-muted">Price: {price}</p>
    </div>
    <div className="card-footer">
      <button className="btn btn-primary btn-sm">
        <IconShoppingCart size={16} className="me-1" />
        Add to cart
      </button>
    </div>
  </div>
);
```

#### Page layout structure

```tsx
// features/orders/OrdersWidget.tsx
const OrdersWidget: React.FC = () => (
  <div className="page-wrapper">
    <div className="page-header d-print-none">
      <div className="container-xl">
        <div className="row g-2 align-items-center">
          <div className="col">
            <h2 className="page-title">Orders</h2>
          </div>
          <div className="col-auto ms-auto">
            <button className="btn btn-primary">
              <IconPlus size={16} className="me-1" />
              New Order
            </button>
          </div>
        </div>
      </div>
    </div>
    <div className="page-body">
      <div className="container-xl">
        <OrdersTable />
      </div>
    </div>
  </div>
);
```

#### Tables

```tsx
// components/shared/DataTable.tsx
interface DataTableProps<T> {
  columns: { key: keyof T; label: string }[];
  rows: T[];
  isLoading: boolean;
  onRowClick?: (row: T) => void;
}

function DataTable<T extends { id: number }>({ columns, rows, isLoading }: DataTableProps<T>) {
  if (isLoading) return <div className="text-center py-4"><div className="spinner-border" /></div>;

  return (
    <div className="table-responsive">
      <table className="table table-vcenter card-table">
        <thead>
          <tr>
            {columns.map((col) => (
              <th key={String(col.key)}>{col.label}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.id}>
              {columns.map((col) => (
                <td key={String(col.key)}>{String(row[col.key])}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
```

#### Loading states — use Tabler Skeleton/Spinner

```tsx
// Always use Tabler's built-in spinner, not custom ones
const LoadingSpinner: React.FC = () => (
  <div className="d-flex justify-content-center py-4">
    <div className="spinner-border text-primary" role="status">
      <span className="visually-hidden">Loading...</span>
    </div>
  </div>
);

// Skeleton placeholder for card lists
const CardSkeleton: React.FC = () => (
  <div className="card card-body placeholder-glow">
    <p className="placeholder col-7 mb-2" />
    <p className="placeholder col-4" />
  </div>
);
```

#### Alerts and feedback

```tsx
// ✅ Use Tabler alert classes — never custom divs for status messages
const SuccessAlert: React.FC<{ message: string }> = ({ message }) => (
  <div className="alert alert-success" role="alert">
    <div className="d-flex">
      <div><IconCheck size={16} className="me-2" /></div>
      <div>{message}</div>
    </div>
  </div>
);

const ErrorAlert: React.FC<{ message: string }> = ({ message }) => (
  <div className="alert alert-danger" role="alert">
    <h4 className="alert-title">Error</h4>
    <div className="text-muted">{message}</div>
  </div>
);
```

#### Modals

```tsx
// Use Tabler modal structure — control visibility via React state, not Bootstrap JS
interface ConfirmModalProps {
  isOpen: boolean;
  title: string;
  message: string;
  onConfirm: () => void;
  onClose: () => void;
  isLoading?: boolean;
}

const ConfirmModal: React.FC<ConfirmModalProps> = ({ isOpen, title, message, onConfirm, onClose, isLoading }) => {
  if (!isOpen) return null;

  return (
    <>
      <div className="modal modal-blur fade show d-block" tabIndex={-1} role="dialog">
        <div className="modal-dialog modal-sm modal-dialog-centered" role="document">
          <div className="modal-content">
            <div className="modal-body">
              <div className="modal-title">{title}</div>
              <div className="text-muted">{message}</div>
            </div>
            <div className="modal-footer">
              <button type="button" className="btn btn-link link-secondary me-auto" onClick={onClose}>
                Cancel
              </button>
              <button
                type="button"
                className="btn btn-danger"
                onClick={onConfirm}
                disabled={isLoading}
              >
                {isLoading ? <span className="spinner-border spinner-border-sm me-1" /> : null}
                Confirm
              </button>
            </div>
          </div>
        </div>
      </div>
      <div className="modal-backdrop fade show" onClick={onClose} />
    </>
  );
};
```

---

### Forms with Tabler + React Hook Form

```tsx
// ✅ Correct: Tabler form classes + RHF registration
const UserForm: React.FC = () => {
  const { register, handleSubmit, formState: { errors } } = useForm<FormData>({
    resolver: zodResolver(schema),
  });

  return (
    <div className="card">
      <div className="card-header">
        <h3 className="card-title">Edit User</h3>
      </div>
      <div className="card-body">
        <form onSubmit={handleSubmit(onSubmit)}>
          <div className="mb-3">
            <label className="form-label required">Email</label>
            <input
              className={`form-control ${errors.email ? 'is-invalid' : ''}`}
              type="email"
              {...register('email')}
            />
            {errors.email && (
              <div className="invalid-feedback">{errors.email.message}</div>
            )}
          </div>

          <div className="mb-3">
            <label className="form-label">Role</label>
            <select className="form-select" {...register('role')}>
              <option value="user">User</option>
              <option value="admin">Admin</option>
            </select>
          </div>

          <div className="card-footer">
            <button type="submit" className="btn btn-primary" disabled={submit.isPending}>
              {submit.isPending
                ? <><span className="spinner-border spinner-border-sm me-2" />Saving...</>
                : 'Save changes'
              }
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};
```

---

### Icons — @tabler/icons-react

```tsx
import {
  IconEdit,
  IconTrash,
  IconPlus,
  IconSearch,
  IconChevronRight,
} from '@tabler/icons-react';

// ✅ Standard usage — always set explicit size, never rely on defaults
<IconEdit size={16} stroke={1.5} className="me-1" />

// ✅ In action buttons
<button className="btn btn-sm btn-icon btn-ghost-danger" onClick={() => onDelete(id)}>
  <IconTrash size={16} />
</button>

// ✅ Empty state
const EmptyState: React.FC<{ message: string }> = ({ message }) => (
  <div className="empty">
    <div className="empty-img">
      <IconSearch size={48} stroke={1} className="text-muted" />
    </div>
    <p className="empty-title">{message}</p>
  </div>
);
```

**Icon rules:**
- Always import only icons you use — tree-shaking keeps bundle small.
- Default `size={16}` for inline/button icons, `size={24}` for standalone, `size={48}` for empty states.
- Default `stroke={1.5}` — matches Tabler's visual style.
- Never use `<img>` for Tabler icons.

---

### Tabler CSS Variables — Custom Overrides

Never override Tabler styles with hardcoded values. Use CSS variables:

```css
/* assets/react/styles/overrides.css */
:root {
  --tblr-primary: #2563eb;        /* Brand primary */
  --tblr-font-size-base: 0.875rem;
  --tblr-border-radius: 6px;
}

/* Feature-scoped overrides — use CSS Modules */
.compactTable {
  --tblr-table-cell-padding-y: 0.35rem;
}
```

```tsx
import styles from './OrdersTable.module.css';

<table className={`table ${styles.compactTable}`}>
```

**Styling rules:**
- Use Tabler utility classes first (`text-muted`, `fw-bold`, `ms-auto`, `d-flex`, `gap-2`).
- CSS Modules only for component-specific overrides — not for layout already covered by Tabler.
- Never write custom CSS for spacing — use Tabler's Bootstrap-based spacing (`m-*`, `p-*`, `gap-*`).
- Never override Tabler classes with `!important`.

---

### Tabler Color Tokens

Use semantic Tabler color classes, not hex values in JSX:

```tsx
// ✅ Correct — semantic and themeable
<Badge color="green">Active</Badge>
<Badge color="red">Inactive</Badge>
<Badge color="yellow">Pending</Badge>
<Badge color="blue">Info</Badge>

// ✅ Status mapping pattern
const STATUS_COLORS = {
  active: 'green',
  inactive: 'red',
  pending: 'yellow',
  draft: 'gray',
} as const;

type Status = keyof typeof STATUS_COLORS;

const StatusBadge: React.FC<{ status: Status }> = ({ status }) => (
  <span className={`badge bg-${STATUS_COLORS[status]}-lt`}>
    {status}
  </span>
);

// ❌ Never
<span style={{ color: '#2fb344' }}>Active</span>
```

---

## Twig Integration

### Mounting React widgets

**Twig template side:**
```twig
{# templates/product/show.html.twig #}

{# 1. Mount point with data attributes (small data) #}
<div
  id="product-configurator"
  data-product-id="{{ product.id }}"
  data-initial-data="{{ product|json_encode|e('html_attr') }}"
></div>

{# 2. Inline JSON for larger payloads #}
<script id="page-bootstrap" type="application/json">
  {{ { user: currentUser, locale: app.request.locale }|json_encode|raw }}
</script>

{# 3. CSRF token for API calls #}
<meta name="csrf-token" content="{{ csrf_token('api') }}">

{# 4. Load the Vite entry #}
{% block javascripts %}
  <script type="module" src="{{ vite_entry_script_tags('product-configurator') }}"></script>
{% endblock %}
```

**React entrypoint side:**
```tsx
// assets/react/entrypoints/product-configurator.tsx
import { createRoot } from 'react-dom/client';
import { QueryClientProvider } from '@tanstack/react-query';
import { queryClient } from '@/services/queryClient';
import ProductConfiguratorWidget from '@/features/product-configurator/ProductConfiguratorWidget';

const el = document.getElementById('product-configurator');
if (el) {
  const props = {
    productId: Number(el.dataset.productId),
    initialData: JSON.parse(el.dataset.initialData ?? 'null'),
  };

  createRoot(el).render(
    <QueryClientProvider client={queryClient}>
      <ProductConfiguratorWidget {...props} />
    </QueryClientProvider>
  );
}
```

**Rules:**
- Always guard with `if (el)` before mounting.
- Always wrap with `QueryClientProvider` at the entrypoint level.
- Always wrap with `ErrorBoundary` — one widget crashing must not break the page.
- Parse `data-*` values immediately at the entrypoint; never pass raw strings to components.
- Use `json_encode|e('html_attr')` in Twig to prevent XSS.

---

## Component Patterns

### Smart / Dumb split

Every feature must separate data concerns from presentation:

```tsx
// features/cart/CartWidget.tsx — SMART (knows about data)
import { useCart } from './useCart';
import CartView from './CartView';

const CartWidget: React.FC = () => {
  const { items, total, removeItem, isLoading } = useCart();

  if (isLoading) return <CartSkeleton />;

  return <CartView items={items} total={total} onRemove={removeItem} />;
};

export default CartWidget;
```

```tsx
// features/cart/CartView.tsx — DUMB (pure UI, no hooks, no fetch)
interface CartViewProps {
  items: CartItem[];
  total: number;
  onRemove: (id: number) => void;
}

const CartView: React.FC<CartViewProps> = ({ items, total, onRemove }) => (
  <div className="cart">
    {items.map((item) => (
      <CartItem key={item.id} {...item} onRemove={onRemove} />
    ))}
    <CartTotal amount={total} />
  </div>
);

export default CartView;
```

**Rules:**
- Dumb components: zero hooks except `useState` for local UI state (open/closed, hover).
- Smart components: no inline JSX logic — delegate everything to dumb components.
- Dumb components must be fully testable with just props.

---

## Custom Hooks

All business logic lives in hooks. Components only call hooks and render JSX.

```ts
// features/cart/useCart.ts
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiFetch } from '@/services/apiClient';
import type { Cart, CartItem } from './cart.types';

export function useCart() {
  const qc = useQueryClient();

  const { data: cart, isLoading } = useQuery<Cart>({
    queryKey: ['cart'],
    queryFn: () => apiFetch('/api/cart'),
  });

  const removeItem = useMutation({
    mutationFn: (itemId: number) =>
      apiFetch(`/api/cart/items/${itemId}`, { method: 'DELETE' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['cart'] }),
  });

  return {
    items: cart?.items ?? [],
    total: cart?.total ?? 0,
    isLoading,
    removeItem: removeItem.mutate,
  };
}
```

**Rules:**
- Hook name always starts with `use`.
- Return plain values, not query objects — components don't need to know about React Query internals.
- `invalidateQueries` after every mutation that changes shared state.
- Provide safe defaults (`?? []`, `?? 0`) so components never crash on undefined.

---

## API Client

Single centralized module — never call `fetch` directly in components or hooks:

```ts
// services/apiClient.ts

function getCsrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

export class ApiError extends Error {
  constructor(
    public status: number,
    message: string
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

export async function apiFetch<T>(url: string, options: RequestInit = {}): Promise<T> {
  const response = await fetch(url, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': getCsrfToken(),
      'X-Requested-With': 'XMLHttpRequest',
      ...options.headers,
    },
  });

  if (!response.ok) {
    const message = await response.text().catch(() => `HTTP ${response.status}`);
    throw new ApiError(response.status, message);
  }

  // Handle 204 No Content
  if (response.status === 204) return undefined as T;

  return response.json() as Promise<T>;
}
```

**Rules:**
- Always send `X-Requested-With: XMLHttpRequest` so Symfony identifies AJAX requests.
- Always send CSRF token on non-GET requests.
- Throw `ApiError` with status code — hooks and query error handlers can discriminate by status.
- Never hardcode base URL — Symfony handles routing.

---

## Forms

Use React Hook Form + Zod for all forms:

```tsx
// features/checkout/CheckoutForm.tsx
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation } from '@tanstack/react-query';
import { apiFetch } from '@/services/apiClient';

const schema = z.object({
  email: z.string().email('Некорректный email'),
  address: z.string().min(10, 'Укажите полный адрес'),
});

type FormData = z.infer<typeof schema>;

const CheckoutForm: React.FC = () => {
  const { register, handleSubmit, formState: { errors } } = useForm<FormData>({
    resolver: zodResolver(schema),
  });

  const submit = useMutation({
    mutationFn: (data: FormData) => apiFetch('/api/checkout', {
      method: 'POST',
      body: JSON.stringify(data),
    }),
  });

  return (
    <form onSubmit={handleSubmit((data) => submit.mutate(data))}>
      <input {...register('email')} />
      {errors.email && <span>{errors.email.message}</span>}

      <input {...register('address')} />
      {errors.address && <span>{errors.address.message}</span>}

      <button type="submit" disabled={submit.isPending}>
        {submit.isPending ? 'Отправка...' : 'Оформить'}
      </button>
    </form>
  );
};
```

**Rules:**
- All form schemas defined with Zod — single source of truth for validation.
- Type derived from schema via `z.infer<typeof schema>` — never define separately.
- Mutation for submission — never manual `fetch` inside `onSubmit`.
- Show `isPending` state on submit button to prevent double-submit.

---

## Error Handling

Wrap every widget in an `ErrorBoundary`:

```tsx
// components/ui/ErrorBoundary.tsx
import { Component, type ReactNode } from 'react';

interface Props { children: ReactNode; fallback?: ReactNode; }
interface State { hasError: boolean; }

class ErrorBoundary extends Component<Props, State> {
  state: State = { hasError: false };

  static getDerivedStateFromError(): State {
    return { hasError: true };
  }

  componentDidCatch(error: Error) {
    console.error('[Widget Error]', error);
    // Optional: send to Sentry
  }

  render() {
    if (this.state.hasError) {
      return this.props.fallback ?? <div className="widget-error">Что-то пошло не так</div>;
    }
    return this.props.children;
  }
}

export default ErrorBoundary;
```

```tsx
// In every entrypoint:
createRoot(el).render(
  <QueryClientProvider client={queryClient}>
    <ErrorBoundary>
      <CartWidget />
    </ErrorBoundary>
  </QueryClientProvider>
);
```

**Rules:**
- Every `createRoot().render()` must have `<ErrorBoundary>` wrapping the widget.
- Query errors handled per-query via `error` state from `useQuery` — don't rely solely on boundary.
- `ApiError` with status 401 → redirect to Symfony login page.
- `ApiError` with status 422 → show validation errors from response body.

---

## Query Client Configuration

```ts
// services/queryClient.ts
import { QueryClient } from '@tanstack/react-query';
import { ApiError } from './apiClient';

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 1000 * 60,       // 1 min — data fresh without refetch
      retry: (failureCount, error) => {
        if (error instanceof ApiError && error.status < 500) return false; // no retry on 4xx
        return failureCount < 2;
      },
    },
    mutations: {
      onError: (error) => {
        if (error instanceof ApiError && error.status === 401) {
          window.location.href = '/login';
        }
      },
    },
  },
});
```

---

## Naming Conventions

| Entity | Convention | Example |
|---|---|---|
| Component file | PascalCase | `ProductCard.tsx` |
| Hook file | camelCase with `use` prefix | `useCart.ts` |
| Type file | camelCase with `.types` | `cart.types.ts` |
| CSS Module | camelCase | `styles.module.css` |
| Entrypoint file | kebab-case | `product-configurator.tsx` |
| API route constants | SCREAMING_SNAKE | `const API_CART = '/api/cart'` |

---

## What NOT To Do

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
import { useCart } from '@/features/cart/useCart'; // inside features/checkout/ — NO

// ❌ Never hardcode strings in Twig data attributes without escaping
data-data="{{ data|json_encode }}"  {# Missing |e('html_attr') — XSS risk #}

// ❌ Never hardcode colors — use Tabler tokens
<span style={{ color: '#2fb344' }}>Active</span>  // NO
<span className="badge bg-green-lt">Active</span>  // YES

// ❌ Never use Bootstrap JS / Tabler JS for modal/dropdown state — use React state
const modal = new bootstrap.Modal(el); // NO — causes conflicts with React DOM

// ❌ Never import all icons
import * as Icons from '@tabler/icons-react'; // Kills tree-shaking, massive bundle
```

---

## Checklist Before Every PR

- [ ] No `any`, no `@ts-ignore`, no non-null assertions
- [ ] Every new widget has `ErrorBoundary` in its entrypoint
- [ ] New Vite entry added to `vite.config.ts` if new page widget
- [ ] Twig `data-*` values use `|e('html_attr')`
- [ ] All fetch calls go through `apiFetch`, not raw `fetch`
- [ ] Smart/Dumb component split in place for non-trivial features
- [ ] Hook returns safe defaults (no `undefined` leaking to JSX)
- [ ] Form uses Zod schema + React Hook Form
- [ ] `isPending` / `isLoading` handled in UI (no frozen buttons)
- [ ] Form inputs use `is-invalid` class + `invalid-feedback` div from Tabler
- [ ] Status/label colors use Tabler semantic tokens (`bg-green-lt`, `text-red`, etc.)
- [ ] Icons imported individually from `@tabler/icons-react` with explicit `size` and `stroke`
- [ ] No Bootstrap JS used for UI state — modals/dropdowns controlled via React state
- [ ] No hardcoded hex/rgb colors in JSX or inline styles