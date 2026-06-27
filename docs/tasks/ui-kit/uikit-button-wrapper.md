# React Wrapper: Button — эталон UI Kit

> Первая React-обёртка над компонентом UI Kit. Эталон, по образцу которого делаются остальные 22.
> Один PR. Без правок логики, без правок Twig.

---

## Цель

После выполнения:

1. `site/assets/react/ui-kit/Button/Button.tsx` — типизированная обёртка над `.btn` классами.
2. `site/assets/react/ui-kit/Button/Button.test.tsx` — минимальные unit-тесты (если в проекте есть testing setup; если нет — пропустить, отметить в Open issues).
3. `site/assets/react/ui-kit/Button/index.ts` — публичный экспорт.
4. `site/assets/react/ui-kit/index.ts` — корневой экспорт UI Kit.
5. В `ui-kit/components/button.html` уже есть `<!-- @react Button -->` (от задачи `uikit-split`), `check-uikit-react-mapping.mjs` теперь **должен** найти двустороннюю связь.

**Visual: ничего не меняется.** Обёртка только типизирует существующие классы. CSS уже в проде после mount `app.css`.

---

## Что НЕ делать

- Не писать CSS внутри обёртки. Никаких `Button.module.css` со своими цветами.
- Не использовать inline-стили (`style={{ color: ... }}`) для визуала.
- Не добавлять npm-пакеты, если не критично. `clsx` уже должен быть в `package.json` (использовался в legacy?). Если нет — единственное допустимое исключение, см. Phase 1.
- Не править `ui-kit/`, `templates/`, `src/`.
- Не мигрировать legacy-страницы на Button.
- Не создавать остальные обёртки (Input, Status, etc.) — это следующие задачи по образцу.

---

## Pre-flight

1. На свежем master:
   ```bash
   git checkout master
   git pull
   ```
2. `git status` чистый.
3. Проверить наличие `clsx`:
   ```bash
   cd site
   grep '"clsx"' package.json
   ```
   - Есть → переходим к Phase 1.
   - Нет → проверить, можно ли обойтись без него (например, написать свой mini-`clsx` в `assets/react/shared/lib/cn.ts`). Если в проекте уже есть аналог (`classnames`, `cn`, etc.) — использовать его.
4. Проверить TypeScript:
   ```bash
   cd site
   grep '"typescript"' package.json
   cat tsconfig.json | head -20
   ```
   Зафиксировать: `strict` режим, `paths`, `jsx`-настройку.
5. Проверить директорию:
   ```bash
   ls -la site/assets/react/ui-kit 2>/dev/null || echo "not exists yet"
   ```
   Скорее всего не существует — создаётся в Phase 2.

---

## Phase 1 — Подготовка зависимостей

Если `clsx` есть — пропустить.

Если нет, и в проекте **нет** альтернативы:

```bash
cd site
npm install --save clsx
```

🛑 STOP перед `npm install` — новая зависимость. Если Владелец не подтверждает явно — создать минимальный helper:

```ts
// site/assets/react/shared/lib/cn.ts
type ClassValue = string | undefined | null | false | 0;

export function cn(...classes: ClassValue[]): string {
  return classes.filter(Boolean).join(' ');
}
```

И в Button использовать `cn` вместо `clsx`. Дальше в задаче везде, где написано `clsx`, читай как «cn или clsx — что выбрано».

---

## Phase 2 — Создать структуру

```bash
cd site
mkdir -p assets/react/ui-kit/Button
```

---

## Phase 3 — Button.tsx

Файл `site/assets/react/ui-kit/Button/Button.tsx`:

```tsx
import clsx from 'clsx';
import type { ButtonHTMLAttributes, FC, ReactNode } from 'react';

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'danger-solid' | 'warning-solid';
export type ButtonSize = 'sm' | 'md' | 'lg';

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  size?: ButtonSize;
  loading?: boolean;
  leadingIcon?: ReactNode;
  trailingIcon?: ReactNode;
}

/**
 * Button — типизированная обёртка над UI Kit `.btn` классами.
 *
 * Не пишет свой CSS. Все стили из ui-kit/components/button.css,
 * подключённого через assets/styles/app.css.
 *
 * @uiKit ui-kit/components/button.html
 * @version 1.4
 *
 * @example
 *   <Button variant="primary" size="md" onClick={save}>Сохранить</Button>
 *   <Button variant="secondary" size="sm" disabled>Отмена</Button>
 *   <Button variant="primary" loading>Сохранение…</Button>
 */
export const Button: FC<ButtonProps> = ({
  variant = 'primary',
  size = 'md',
  loading = false,
  leadingIcon,
  trailingIcon,
  className,
  disabled,
  children,
  type = 'button',
  ...rest
}) => (
  <button
    type={type}
    className={clsx(
      'btn',
      `btn-${variant}`,
      `btn-${size}`,
      loading && 'btn-loading',
      className,
    )}
    disabled={disabled || loading}
    aria-busy={loading || undefined}
    {...rest}
  >
    {leadingIcon}
    {children}
    {trailingIcon}
  </button>
);
```

**Замечания по реализации:**

- `type="button"` по умолчанию — защита от случайного submit в форме. Owner может переопределить через пропс.
- `disabled || loading` — при `loading` кнопка не кликается.
- `aria-busy` для скринридеров.
- `className` принимается и мерджится через `clsx` — позволяет добавлять модификаторы снаружи без копипасты обёртки.
- `leadingIcon`/`trailingIcon` — слоты для иконок (когда дойдём до иконочной системы — будут принимать `<svg>`).

---

## Phase 4 — index.ts (экспорты)

`site/assets/react/ui-kit/Button/index.ts`:

```ts
export { Button } from './Button';
export type { ButtonProps, ButtonVariant, ButtonSize } from './Button';
```

`site/assets/react/ui-kit/index.ts`:

```ts
// UI Kit React wrappers — public API.
// Каждый компонент — обёртка над CSS-классами из ui-kit/.
// See ui-kit/components/<name>.html for reference markup.

export * from './Button';
```

---

## Phase 5 — Тест (если в проекте есть testing setup)

```bash
cd site
grep -E '"(vitest|jest|@testing-library)"' package.json
```

### Если тестовый стек установлен

`site/assets/react/ui-kit/Button/Button.test.tsx`:

```tsx
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest'; // или from '@jest/globals' для jest
import { Button } from './Button';

describe('Button', () => {
  it('renders children', () => {
    render(<Button>Save</Button>);
    expect(screen.getByRole('button', { name: 'Save' })).toBeInTheDocument();
  });

  it('applies default classes', () => {
    render(<Button>X</Button>);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('btn');
    expect(btn.className).toContain('btn-primary');
    expect(btn.className).toContain('btn-md');
  });

  it('applies variant and size classes', () => {
    render(<Button variant="secondary" size="lg">X</Button>);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('btn-secondary');
    expect(btn.className).toContain('btn-lg');
  });

  it('disabled when loading', () => {
    render(<Button loading>X</Button>);
    const btn = screen.getByRole('button');
    expect(btn).toBeDisabled();
    expect(btn.className).toContain('btn-loading');
    expect(btn.getAttribute('aria-busy')).toBe('true');
  });

  it('does not fire onClick when disabled or loading', () => {
    const onClick = vi.fn();
    render(<Button onClick={onClick} disabled>X</Button>);
    fireEvent.click(screen.getByRole('button'));
    expect(onClick).not.toHaveBeenCalled();
  });

  it('merges custom className', () => {
    render(<Button className="extra-class">X</Button>);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('btn');
    expect(btn.className).toContain('extra-class');
  });

  it('renders leading icon', () => {
    render(<Button leadingIcon={<span data-testid="ic">★</span>}>X</Button>);
    expect(screen.getByTestId('ic')).toBeInTheDocument();
  });

  it('type defaults to button (prevents accidental submit)', () => {
    render(<Button>X</Button>);
    expect(screen.getByRole('button').getAttribute('type')).toBe('button');
  });
});
```

Запустить:
```bash
cd site
npm test -- Button.test
```

Все 8 тестов должны пройти.

### Если тестового стека нет

Пропустить Phase 5. В отчёте PR отметить:
- «Тесты не написаны: в проекте нет setup для frontend-тестов.»
- «Open issue: добавить Vitest + @testing-library/react. После — написать тесты для Button.»

---

## Phase 6 — Verify

```bash
cd site

# 1. TypeScript типы
npx tsc --noEmit
# Ожидаемо: exit 0, без ошибок.

# 2. ESLint (правила границ из предыдущего PR)
npx eslint assets/react/ui-kit/Button/Button.tsx
# Ожидаемо: exit 0. Файл не импортирует из _legacy/ — правила не должны срабатывать.

# 3. UI Kit ↔ React mapping
node tools/check-uikit-react-mapping.mjs
# Ожидаемо: количество ref-no-react-mapping УМЕНЬШИЛОСЬ на 1 (Button теперь сопоставлен).
# В выводе должно быть видно: 'button.html → Button' OK.

# 4. UI Kit классы
node tools/check-ui-kit-classes.mjs
# Ожидаемо: 0 нарушений в новом коде. Button использует btn / btn-{variant} / btn-{size} / btn-loading — все из UI Kit.

# 5. Build (опционально)
npm run build
# Ожидаемо: exit 0. Button не вошёл в bundle (нигде не импортирован пока) — Vite tree-shake его уберёт.
```

---

## Phase 7 — Smoke (опционально, рекомендуется)

Создать **временную** тестовую страницу для глазами проверить рендер.

```bash
cd site
mkdir -p assets/react/_smoke
cat > assets/react/_smoke/button-demo.tsx << 'EOF'
import { createRoot } from 'react-dom/client';
import { Button } from '@/react/ui-kit/Button';

const App = () => (
  <div style={{ padding: 24, display: 'flex', flexDirection: 'column', gap: 16 }}>
    <h1>Button smoke</h1>

    <div style={{ display: 'flex', gap: 8 }}>
      <Button variant="primary" size="sm">Primary sm</Button>
      <Button variant="primary" size="md">Primary md</Button>
      <Button variant="primary" size="lg">Primary lg</Button>
    </div>

    <div style={{ display: 'flex', gap: 8 }}>
      <Button variant="secondary">Secondary</Button>
      <Button variant="ghost">Ghost</Button>
      <Button variant="danger">Danger</Button>
      <Button variant="danger-solid">Danger solid</Button>
      <Button variant="warning-solid">Warning solid</Button>
    </div>

    <div style={{ display: 'flex', gap: 8 }}>
      <Button disabled>Disabled</Button>
      <Button loading>Loading</Button>
    </div>
  </div>
);

const el = document.getElementById('smoke-root');
if (el) createRoot(el).render(<App />);
EOF
```

**Этот файл — временный.** Не коммитить его в PR. После проверки удалить:

```bash
rm -rf assets/react/_smoke
```

Опционально: для визуальной проверки можно прицепить в `vite.config.js` как одноразовый entry, открыть в браузере, посмотреть, удалить entry. Если возиться неохота — пропустить, тесты + типы достаточны.

---

## Self-review

- [ ] Создан `site/assets/react/ui-kit/Button/Button.tsx` строго по шаблону Phase 3
- [ ] JSDoc содержит `@uiKit ui-kit/components/button.html` и `@version 1.4`
- [ ] Нет своего CSS, нет inline-стилей с цветами
- [ ] `type="button"` по умолчанию
- [ ] `disabled` при `loading=true`
- [ ] `aria-busy` при `loading=true`
- [ ] `className` мерджится через clsx
- [ ] Создан `index.ts` (компонент) + `assets/react/ui-kit/index.ts` (корневой)
- [ ] Тесты написаны (или явный note в отчёте о причине пропуска)
- [ ] `npx tsc --noEmit` — green
- [ ] `eslint` на новом файле — green
- [ ] `check-uikit-react-mapping.mjs` — Button теперь сопоставлен с `button.html`
- [ ] `check-ui-kit-classes.mjs` — 0 нарушений
- [ ] `_smoke/` удалён, если создавался
- [ ] `git status` показывает только новые файлы в `assets/react/ui-kit/Button/`, `assets/react/ui-kit/index.ts`, опционально `assets/react/shared/lib/cn.ts`
- [ ] Никакие другие файлы (Twig, src, vite.config, package.json без явного STOP) не правились

---

## Commit + PR

```bash
git checkout -b chore/uikit-button-wrapper

git add site/assets/react/ui-kit/Button/
git add site/assets/react/ui-kit/index.ts
# (если создавался shared/lib/cn.ts)
git add site/assets/react/shared/lib/cn.ts 2>/dev/null

git commit -m "feat(ui-kit/react): add Button wrapper as canonical example

First typed React wrapper over UI Kit CSS classes.
- Variants: primary | secondary | ghost | danger | danger-solid | warning-solid
- Sizes: sm | md | lg
- States: disabled, loading (with aria-busy)
- No own CSS; classes come from ui-kit/components/button.css via app.css

JSDoc \\@uiKit annotation enables check-uikit-react-mapping.mjs to
verify two-way binding between HTML reference and React wrapper.

This is the template for the remaining 22 wrappers."

git push -u origin chore/uikit-button-wrapper

gh pr create --draft \
  --title "feat(ui-kit/react): Button wrapper" \
  --body "Эталонная React-обёртка UI Kit.

## Что внутри
- Button.tsx — типизированная обёртка над .btn классами
- Button.test.tsx — 8 unit-тестов (или отметка о пропуске)
- index.ts — публичный экспорт

## Verification
- [x] tsc --noEmit green
- [x] eslint green
- [x] check-uikit-react-mapping: -1 ref-no-react-mapping (Button matched)
- [x] check-ui-kit-classes: 0 violations

## Что НЕ делает
- Не правит Twig, не правит ui-kit/, не правит legacy/.
- Не пишет свой CSS — только использует классы UI Kit.

## Next
По образцу Button — обёртки для остальных 22 компонентов (Input, Status, Badge, Money, KPI, Card, etc.). Каждая отдельным PR."
```

---

## Что НИКОГДА не делать

```
писать свой CSS в Button.tsx или Button.module.css       — никогда
hardcode цвета, отступы в TSX                            — никогда
менять CSS в ui-kit/components/button.css                 — другая задача
создавать остальные обёртки в этом PR                    — нет
мигрировать legacy/* на Button                           — нет
менять Twig                                              — нет
менять package.json без явного STOP+апрува               — нет
mergить PR автоматически                                 — только Владелец
```

---

## Closing

🛑 STOP. Draft PR открыт. Жду Владельца: review кода + апрув merge.

После merge — следующие обёртки по образцу: Input, Status, Badge, Money, KPI, Card. По одной за PR.
