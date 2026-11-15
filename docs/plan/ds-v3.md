# ДС «Ваш Финдир» v3.4 — внедрение в продукт

Статус: решение Владельца 2026-11-15. Источник — UI Kit v3.4 (канвас Claude Design,
страница `ui-kit-v3.0`). Задача для агента — `docs/design-system/PROMPT-ds-v3.md`,
приёмка — `docs/design-system/CHECKLIST-ds-v3.md`.

## 1. Решения Владельца (2026-11-15)

1. **Эталон визуала — UI Kit v3.4.** Листы `docs/design-system/sheets/` заменены
   листами v3.4 (`DS-*.html`, обычный HTML, открываются в браузере; общий CSS —
   `sheets/ds-kit.css`, скриншоты — `sheets/png/`). Прежние `DS-*.dc.html` удалены,
   история — в git. Правило «код расходится с листом — виноват код» остаётся.
2. **Tailwind — только в новом React-слое** (`_layout/app.html.twig`, `assets/react/**`,
   `apps/**`), как утилиты поверх CSS-переменных кита. `corePlugins.preflight: false`,
   `content` — только файлы нового слоя. Легаси (Tabler) и CSS кита
   (`assets/styles/kit/`) на Tailwind не переписываются. Референс конфига —
   `docs/design-system/tailwind/`.
3. **Тёмная тема — отложена.** Токены тёмной темы в ките есть, в продукт не
   включаются: ни блока `[data-theme="dark"]`, ни `@media (prefers-color-scheme: dark)`
   в CSS продукта на этом этапе (иначе у пользователей с тёмной ОС интерфейс
   станет тёмным без проверки экранов).

## 2. Что меняется визуально (коротко)

| | Сейчас | v3.4 |
|---|---|---|
| Шрифт | Inter | Manrope |
| Primary: ссылки, табы, чекбоксы, фокус, **главная кнопка** | синий `#1D4ED8` | navy `#1E2A44` (hover `#141B2E`) |
| Красный бренда `#B00020` | бренд + деструктивные действия | **только бренд** (логотип, промо) — Decision 44 |
| Ошибка, деструктивное действие, снижение | красный `#9A001B` | оранжевый `#C2410C` — Decision 43 |
| Предупреждение | `#9A4A00` | жёлтый `#8A5A00` / точка `#F5B400` |
| Рост, успех | `#1D7A30` | `#0E7A4F` |
| Info | `#164F94` | `#1E40AF` (отдельная роль `--info-*`) |
| Фон / граница / граница поля | `#F7F8FC` / `#DDE1EC` / `#8590A8` | `#F5F5F7` / `#E3E4E8` / `#8A8E98` |
| Фокус | кольцо `rgba(29,78,216,.2)` | `outline 2px` navy, offset 2px — Decision 36 |

## 3. Миграция токенов

### 3.1. Новый слой — `site/assets/styles/kit/tokens/*.css`

Имена токенов кита совпадают с v3.4 (188 общих). Меняются **значения** (107),
добавляются 173 токена, 4 токена есть только в коде. Источник новых значений —
`:root` в `docs/design-system/sheets/ds-kit.css` (блок от `ТОКЕНЫ v3.0` до
`DEPRECATED ALIASES` включительно; блоки тёмной темы — не переносить, см. §1.3).

**Смысловые изменения — проверить потребителей вручную:**

- `--border-strong`: было «декоративная граница посильнее» `#C3C8D6`, стало
  «интерактивная граница ≥3:1» `#8A8E98`. Потребители: `kit/components/table.css`
  (2), `page-tabs.css`, `kpi.css`, `tokens/layout.css` (3), `pages/auth.css` (5).
  Декоративные места (`table.css`, `page-tabs.css` hover) перевести на
  `--border-hover`; границы контролов оставить.
- `--button-secondary-border` в v3.4 = `--border` (было `--border-strong`).
- `--text-muted` = `--text-tertiary` (`#6A6E79`), `--text-secondary` = `#585D68`
  (было navy `#2B3350`).
- `--button-primary-bg` = `--color-primary` = navy. Красная кнопка в продукте не
  используется нигде.
- Токены только в коде: `--bg-ghost-hover`, `--bg-neutral-tint`, `--r-5` — найти
  потребителей; оставить алиасами на ближайший токен v3.4 или удалить, если
  потребителей нет. `--modal-width-xl` — удалить вместе с `.mdl--xl` (Decision 45).

Изменённые значения (сгенерировано сравнением `kit/tokens/*.css` с v3.4):

#### `kit/tokens/colors.css` — 33

| Токен | Сейчас | v3.4 |
|---|---|---|
| `--color-primary` | `#1d4ed8` | `#1E2A44` |
| `--color-primary-hover` | `#1a40b0` | `#0B1020` |
| `--color-primary-tint` | `#e8effd` | `#EEF0F5` |
| `--color-primary-border` | `#b9cbf5` | `#C5CBD8` |
| `--color-primary-selection` | `#d6e2fb` | `#DEE2EB` |
| `--text-secondary` | `#2b3350` | `#585D68` |
| `--text-tertiary` | `#525b78` | `#6A6E79` |
| `--text-muted` | `#6a738f` | `#6A6E79` |
| `--text-disabled` | `#6a738f` | `#9EA2AB` |
| `--text-faint` | `#c3c8d6` | `#C4C7CE` |
| `--border` | `#dde1ec` | `#E3E4E8` |
| `--border-soft` | `#e8ecf7` | `#F1F1F4` |
| `--border-strong` | `#c3c8d6` | `#8A8E98` |
| `--bg-page` | `#f7f8fc` | `#F5F5F7` |
| `--bg-subtle` | `#f4f6fb` | `#F8F8FA` |
| `--bg-row-hover` | `#f4f6fb` | `#FAFAFB` |
| `--bg-row-selected` | `#e8ecf7` | `#F3F4F8` |
| `--color-primary-active` | `#1a40b0` | `#0B1020` |
| `--border-hover` | `#c3c8d6` | `#D4D6DC` |
| `--bg-subtle-hover` | `#eef1f8` | `#EFEFF2` |
| `--danger-text-hover` | `#8f0019` | `#9A3412` |
| `--warning-text-hover` | `#7a3a00` | `#6B4500` |
| `--border-dark` | `#1F2937` | `#23262F` |
| `--report-head-meta` | `#6E89C6` | `#8E9BBB` |
| `--focus-ring-primary` | `rgba(29, 78, 216, 0.2)` | `rgba(30,42,68,0.25)` |
| `--focus-ring-input` | `rgba(29, 78, 216, 0.2)` | `rgba(30,42,68,0.14)` |
| `--focus-ring-danger` | `rgba(176, 0, 32, 0.15)` | `rgba(194,65,12,0.14)` |
| `--shadow-primary-glow` | `rgba(29, 78, 216, 0.25)` | `rgba(11,16,32,0.18)` |
| `--shadow-danger-glow` | `rgba(176, 0, 32, 0.22)` | `rgba(194,65,12,0.22)` |
| `--shadow-warning-glow` | `rgba(154, 74, 0, 0.22)` | `rgba(146, 64, 14, 0.22)` |
| `--src-manual-bg` | `#F4F6FA` | `#F5F5F7` |
| `--src-manual-text` | `#475569` | `#585D68` |
| `--src-manual-border` | `#E2E8F0` | `#E3E4E8` |

#### `kit/tokens/layout.css` — 42

| Токен | Сейчас | v3.4 |
|---|---|---|
| `--app-header-border` | `#dde1ec` | `#E3E4E8` |
| `--abc-star-bg` | `#e4f5e7` | `#EAF6F0` |
| `--abc-star-text` | `#1d7a30` | `#0E7A4F` |
| `--abc-attention-bg` | `#fff4e5` | `#FFF8E1` |
| `--abc-attention-text` | `#9a4a00` | `#8A5A00` |
| `--abc-mid-bg` | `#f4f6fb` | `#F8F8FA` |
| `--abc-weak-bg` | `#f7f8fc` | `#F5F5F7` |
| `--abc-weak-text` | `#525b78` | `#6A6E79` |
| `--abc-tail-bg` | `#fdecee` | `#FFF7ED` |
| `--abc-tail-text` | `#9a001b` | `#C2410C` |
| `--button-primary-bg` | `#1d4ed8` | `#1E2A44` |
| `--button-primary-bg-hover` | `#1a40b0` | `#141B2E` |
| `--button-primary-bg-active` | `#1a40b0` | `#0B1020` |
| `--button-secondary-bg-hover` | `#eef1f8` | `#F5F5F7` |
| `--button-secondary-border` | `#c3c8d6` | `#E3E4E8` |
| `--button-secondary-border-hover` | `#c3c8d6` | `#D4D6DC` |
| `--button-ghost-text` | `#2b3350` | `#585D68` |
| `--button-ghost-bg-hover` | `rgba(11, 16, 32, 0.07)` | `#F5F5F7` |
| `--input-border` | `#c3c8d6` | `#8A8E98` |
| `--input-border-focus` | `#1d4ed8` | `#1E2A44` |
| `--input-border-error` | `#b00020` | `#F97316` |
| `--input-placeholder` | `#6a738f` | `#6A6E79` |
| `--input-focus-ring` | `rgba(29, 78, 216, 0.2)` | `rgba(30,42,68,0.14)` |
| `--input-error-ring` | `rgba(176, 0, 32, 0.15)` | `rgba(194,65,12,0.14)` |
| `--card-border` | `#dde1ec` | `#E3E4E8` |
| `--card-shadow` | `0 1px 2px rgba(11, 16, 32, 0.04)` | `0 1px 2px var(--alpha-ink-06)` |
| `--form-error-text` | `#9a001b` | `#C2410C` |
| `--form-helper-text` | `#525b78` | `#6A6E79` |
| `--form-label-text` | `#0b1020` | `#6A6E79` |
| `--form-required-mark` | `#b00020` | `#F97316` |
| `--status-success-bg` | `#e4f5e7` | `#EAF6F0` |
| `--status-success-text` | `#1d7a30` | `#0E7A4F` |
| `--status-success-dot` | `#2fb344` | `#16A06A` |
| `--status-danger-bg` | `#fdecee` | `#FFF7ED` |
| `--status-danger-text` | `#9a001b` | `#C2410C` |
| `--status-danger-dot` | `#b00020` | `#F97316` |
| `--status-warning-bg` | `#fff4e5` | `#FFF8E1` |
| `--status-warning-text` | `#9a4a00` | `#8A5A00` |
| `--status-warning-dot` | `#f76707` | `#F5B400` |
| `--status-neutral-bg` | `#e8ecf7` | `#F5F5F7` |
| `--status-neutral-text` | `#46525f` | `#585D68` |
| `--status-neutral-dot` | `#6a738f` | `#9EA2AB` |

#### `kit/tokens/radius.css` — 3

| Токен | Сейчас | v3.4 |
|---|---|---|
| `--r-2` | `4px` | `6px` |
| `--r-3` | `4px` | `6px` |
| `--r-4` | `4px` | `8px` |

#### `kit/tokens/semantic.css` — 17

| Токен | Сейчас | v3.4 |
|---|---|---|
| `--success-bg` | `#e4f5e7` | `#EAF6F0` |
| `--success-border` | `#b4e0bc` | `#CBEADB` |
| `--success-text` | `#1d7a30` | `#0E7A4F` |
| `--success-dot` | `#2fb344` | `#16A06A` |
| `--danger-bg` | `#fdecee` | `#FFF7ED` |
| `--danger-border` | `#f0c2c9` | `#FED7AA` |
| `--danger-text` | `#9a001b` | `#C2410C` |
| `--danger-dot` | `#b00020` | `#F97316` |
| `--warning-bg` | `#fff4e5` | `#FFF8E1` |
| `--warning-border` | `#f5d3a8` | `#FDE68A` |
| `--warning-text` | `#9a4a00` | `#8A5A00` |
| `--warning-dot` | `#f76707` | `#F5B400` |
| `--info-bg` | `#e3eefb` | `#EEF3FE` |
| `--info-border` | `#b9d3f2` | `#DBE6FF` |
| `--info-text` | `#164f94` | `#1E40AF` |
| `--info-dot` | `#1d4ed8` | `#1A56DB` |
| `--neutral-tint-text` | `#46525f` | `#585D68` |

#### `kit/tokens/shadows.css` — 6

| Токен | Сейчас | v3.4 |
|---|---|---|
| `--shadow-sm` | `0 1px 2px rgba(11, 16, 32, 0.04)` | `0 1px 2px var(--alpha-ink-06)` |
| `--shadow-card-hover` | `0 4px 12px rgba(11, 16, 32, 0.06)` | `0 4px 12px var(--alpha-ink-04)` |
| `--shadow-primary` | `none` | `0 1px 0 var(--alpha-white-15) inset, 0 4px 10px var(--shadow` |
| `--shadow-menu` | `0 4px 12px rgba(11, 16, 32, 0.12)` | `0 16px 36px var(--alpha-ink-14), 0 2px 8px var(--alpha-ink-0` |
| `--shadow-modal` | `0 12px 32px rgba(11, 16, 32, 0.18)` | `0 24px 60px rgba(11,18,32,0.28), 0 4px 12px rgba(11,18,32,0.` |
| `--shadow-drawer` | `-12px 0 32px rgba(11, 16, 32, 0.18)` | `-20px 0 60px rgba(11,18,32,0.22)` |

#### `kit/tokens/typography.css` — 6

| Токен | Сейчас | v3.4 |
|---|---|---|
| `--font-family` | `'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Robo` | `'Manrope', system-ui, -apple-system, 'Segoe UI', sans-serif` |
| `--font-9_5` | `9.5px` | `11px` |
| `--font-10_5` | `10.5px` | `11px` |
| `--font-11_5` | `11.5px` | `12px` |
| `--font-12_5` | `12.5px` | `13px` |
| `--font-13_5` | `13.5px` | `14px` |

Новые токены по семействам:

| Семейство | Токены |
|---|---|
| `--alpha-*` | 21: `--alpha-blue-0`, `--alpha-blue-12`, `--alpha-blue-16`, `--alpha-blue-25`, `--alpha-crimson-25`, `--alpha-ink-04`, `--alpha-ink-06`, `--alpha-ink-10`, `--alpha-ink-14`, `--alpha-ink-20`, `--alpha-navy-0`, `--alpha-navy-14` … |
| `--amber-*` | 6: `--amber-200`, `--amber-400`, `--amber-50`, `--amber-500`, `--amber-800`, `--amber-900` |
| `--app-*` | 1: `--app-header-gutter` |
| `--blue-*` | 8: `--blue-100`, `--blue-200`, `--blue-25`, `--blue-400`, `--blue-50`, `--blue-600`, `--blue-700`, `--blue-800` |
| `--bp-*` | 4: `--bp-lg`, `--bp-md`, `--bp-sm`, `--bp-xl` |
| `--button-*` | 1: `--button-ghost-bg` |
| `--color-*` | 4: `--color-accent`, `--color-accent-active`, `--color-accent-hover`, `--color-accent-tint` |
| `--control-*` | 2: `--control-h`, `--control-h-sm` |
| `--crimson-*` | 5: `--crimson-100`, `--crimson-50`, `--crimson-600`, `--crimson-700`, `--crimson-800` |
| `--cyan-*` | 1: `--cyan-500` |
| `--drawer-*` | 1: `--drawer-width` |
| `--dur-*` | 3: `--dur-base`, `--dur-fast`, `--dur-slow` |
| `--ease-*` | 2: `--ease-exit`, `--ease-standard` |
| `--focus-*` | 1: `--focus-ring-color` |
| `--fs-*` | 10: `--fs-2xl`, `--fs-2xs`, `--fs-3xl`, `--fs-4xl`, `--fs-5xl`, `--fs-lg`, `--fs-md`, `--fs-sm`, `--fs-xl`, `--fs-xs` |
| `--fw-*` | 5: `--fw-bold`, `--fw-extrabold`, `--fw-medium`, `--fw-regular`, `--fw-semibold` |
| `--gray-*` | 15: `--gray-0`, `--gray-100`, `--gray-150`, `--gray-200`, `--gray-25`, `--gray-300`, `--gray-400`, `--gray-450`, `--gray-50`, `--gray-500`, `--gray-600`, `--gray-75` … |
| `--green-*` | 8: `--green-100`, `--green-50`, `--green-500`, `--green-700`, `--green-brand-100`, `--green-brand-50`, `--green-brand-500`, `--green-brand-700` |
| `--grid-*` | 2: `--grid-columns`, `--grid-gutter` |
| `--highlight-*` | 2: `--highlight-bg`, `--highlight-text` |
| `--input-*` | 4: `--input-bg-disabled`, `--input-border-disabled`, `--input-border-hover`, `--input-icon-slot` |
| `--lh-*` | 3: `--lh-base`, `--lh-snug`, `--lh-tight` |
| `--ls-*` | 3: `--ls-caps`, `--ls-normal`, `--ls-tight` |
| `--navy-*` | 8: `--navy-100`, `--navy-200`, `--navy-25`, `--navy-400`, `--navy-50`, `--navy-700`, `--navy-800`, `--navy-900` |
| `--orange-*` | 6: `--orange-100`, `--orange-200`, `--orange-50`, `--orange-500`, `--orange-700`, `--orange-800` |
| `--r-*` | 5: `--r-lg`, `--r-md`, `--r-sm`, `--r-xl`, `--r-xs` |
| `--red-*` | 5: `--red-200`, `--red-50`, `--red-500`, `--red-700`, `--red-800` |
| `--ring-*` | 2: `--ring-error`, `--ring-focus` |
| `--s-*` | 7: `--s-0`, `--s-0_5`, `--s-10`, `--s-1_5`, `--s-2_5`, `--s-3_5`, `--s-9` |
| `--sb-*` | 1: `--sb-indent` |
| `--shadow-*` | 1: `--shadow-inset-hl` |
| `--sky-*` | 1: `--sky-500` |
| `--spark-*` | 2: `--spark-fill-end`, `--spark-fill-start` |
| `--toggle-*` | 1: `--toggle-off-bg` |
| `--type-*` | 8: `--type-body`, `--type-body-strong`, `--type-caps`, `--type-hero`, `--type-kpi`, `--type-label`, `--type-title`, `--type-title-sm` |
| `--violet-*` | 4: `--violet-100`, `--violet-50`, `--violet-500`, `--violet-700` |
| `--yellow-*` | 4: `--yellow-50`, `--yellow-500`, `--yellow-800`, `--yellow-900` |
| `--z-*` | 6: `--z-dropdown`, `--z-header`, `--z-overlay`, `--z-raised`, `--z-sticky`, `--z-toast` |

### 3.2. Легаси — `site/assets/styles/design-tokens.css`

Имена `--vf-*` и слой `--tblr-*` сохраняются; меняются значения. Правило: значение
`--vf-*` = значение соответствующего semantic-токена v3.4 (hex, без `var()` на
токены кита — легаси их не подключает). RGB-триплеты `--tblr-*-rgb` пересчитать из
новых hex.

| `--vf-*` | Сейчас | v3.4 | Роль v3.4 |
|---|---|---|---|
| `--vf-bg` | `#f7f8fc` | `#F5F5F7` | `--bg-page` |
| `--vf-surface` | `#FFFFFF` | `#FFFFFF` | `--bg-card` |
| `--vf-navy-50` / `--vf-surface-alt` | `#f4f6fb` | `#F8F8FA` | `--bg-subtle` |
| `--vf-hover-bg` | `#eef1f8` | `#EFEFF2` | `--bg-subtle-hover` |
| `--vf-table-readonly-bg` | `#fafbfd` | `#FAFAFB` | `--bg-row-hover` |
| `--vf-table-group-bg` | `#f8f9fd` | `#F8F8FA` | `--bg-subtle` |
| `--vf-cell-hover-bg` | `#f1f5ff` | `#F3F4F8` | `--bg-row-selected` |
| `--vf-border` | `#dde1ec` | `#E3E4E8` | `--border` |
| `--vf-border-strong` | `#c3c8d6` | `#D4D6DC` | `--border-hover` |
| `--vf-border-control` | `#8590a8` | `#8A8E98` | `--border-strong` / `--input-border` |
| `--vf-navy-100` | `#e8ecf7` | `#F1F1F4` | `--border-soft` |
| `--vf-navy-900` / `--vf-text` | `#0b1020` | `#0B1020` | `--text-strong` |
| `--vf-navy-700` | `#2b3350` | `#585D68` | `--text-secondary` |
| `--vf-text-muted` | `#525b78` | `#6A6E79` | `--text-tertiary` |
| `--vf-navy-500` | `#6a738f` | `#9EA2AB` | `--text-disabled` |
| `--vf-neutral-text` | `#46525f` | `#585D68` | `--neutral-tint-text` |
| `--vf-primary` | `#1d4ed8` | `#1E2A44` | `--color-primary` |
| `--vf-primary-hover` | `#1a40b0` | `#141B2E` | `--button-primary-bg-hover` |
| `--vf-primary-lt` | `#e8effd` | `#EEF0F5` | `--color-primary-tint` |
| `--vf-brand-red` | `#b00020` | `#B00020` | `--color-accent` — **только бренд** |
| `--vf-brand-red-hover` | `#8f0019` | `#8C0019` | `--color-accent-hover` |
| `--vf-danger-text` | `#9a001b` | `#C2410C` | `--danger-text` |
| `--vf-danger-bg` | `#fdecee` | `#FFF7ED` | `--danger-bg` |
| `--vf-danger-border` | `#f0c2c9` | `#FED7AA` | `--danger-border` |
| `--vf-yellow` / `--vf-warn-text` | `#9a4a00` | `#8A5A00` | `--warning-text` |
| `--vf-yellow-lt` / `--vf-warn-bg` | `#fff4e5` | `#FFF8E1` | `--warning-bg` |
| `--vf-warn-border` | `#f5d3a8` | `#FDE68A` | `--warning-border` |
| `--vf-green` / `--vf-ok-text` | `#1d7a30` | `#0E7A4F` | `--success-text` |
| `--vf-green-lt` / `--vf-ok-bg` | `#e4f5e7` | `#EAF6F0` | `--success-bg` |
| `--vf-green-border` | `#b4e0bc` | `#CBEADB` | `--success-border` |
| `--vf-info-text` | `#164f94` | `#1E40AF` | `--info-text` |
| `--vf-info-bg` | `#e3eefb` | `#EEF3FE` | `--info-bg` |
| `--vf-info-border` | `#b9d3f2` | `#DBE6FF` | `--info-border` |
| `--vf-font-sans`, `--vf-font-numeric` | Inter | `'Manrope', system-ui, …` | `--font-family` |
| `--vf-feature-settings` | `"cv03","cv04","cv11"` | удалить (фичи Inter) | — |
| `--vf-shadow-focus` | `0 0 0 3px rgba(29,78,216,.2)` | `0 0 0 3px rgba(30,42,68,.14)` | `--ring-focus` (поля); остальные — `outline` |
| `--vf-duration` / `-fast` / `-slow` | 180 / 120 / 320ms | 160 / 120 / 240ms | `--dur-*` |

Деструктивные действия в легаси (`btn-danger`, `--tblr-danger`) переводятся на
`--vf-danger-text` (оранжевый), а не на `--vf-brand-red`. `--vf-chart-*`,
`--vf-ai-*`, `--vf-marker` — без изменений (в ките палитры графиков нет, `TODO(ds)`
остаётся).

## 4. Компоненты кита (Decision 45 — ревизия)

Потребители посчитаны `rg` по `site/templates` и `site/assets` на 2026-11-15.

| Действие | Что | Потребителей | Как |
|---|---|---|---|
| Слить | `.h-btn`, `.h-btn--icon` → `.btn.btn-md.btn-secondary`, `.btn-icon` | 4 | удалить `components/h-btn.css` после замены |
| Слить | `.search-field`, `.password-field` → `.input-wrap` + `.input-icon` / `.input-action`, модификаторы `.input--icon` / `.input--action`, `.input-wrap--clearable` | 2 + 2 | удалить `search-input.css`, `password-input.css` |
| Слить | Breadcrumbs → часть AppHeader | 39 | класс `.crumbs` остаётся, меняется только место в документации |
| Слить | Direction → раздел Money | 0 (классом) | файл `direction.css` оставить |
| Слить | `.picker-tab` → `.tabs` / `.tab` | 1 | |
| Удалить | паттерн `.field` / `.field-label` (`patterns/row-form-field.css`) | 12 | сначала заменить на `.form-field` / `.form-label` |
| Удалить | `.mdl--side` (side modal) → drawer | 1 | |
| Удалить | `.mdl--xl` | 2 | → drawer 680 или `.mdl--l` |
| Удалить | `.kpi--accent-*` | 2 | убрать модификатор |
| Удалить | `.mp-badge` (full badge), размеры `.mp-mark--sm/--lg` | 1 | оставить `.mp-mark` одного размера + `.mp-chip` |
| Удалить | `.t-scrollbar` (dual scrollbar) | 1 | |
| Оставить в коде | `coverage.css` (`.cov-*`) | 21 | из кита убран как внутренний админ-компонент; файл остаётся стилем страницы мониторинга загрузок, перенести в `assets/styles/pages/` |
| Добавить | `.btn-icon`, `.link`, `.input-icon`, `.input-action`, `.input--icon`, `.input--action`, `.input-wrap--clearable` | — | значения — `sheets/ds-kit.css` |

## 5. Этапы

Каждый этап — отдельный PR, «один коммит — одна задача». Сверка — скриншоты
ключевых экранов до/после в 1440: «Операции ДДС», операция ДДС (drawer/страница),
«Юнит-экономика», дашборд, бюджет, авторизация.

1. **Токены нового слоя** — `kit/tokens/*.css` по §3.1, без тёмной темы.
2. **Токены легаси** — `design-tokens.css` по §3.2, деструктивные действия → оранжевый.
3. **Шрифт** — Manrope в обоих слоях (`_layout/legacy.html.twig`, `_layout/app.html.twig`,
   `apps/**`), убрать загрузку Inter.
4. **Компоненты** — §4: слияния, удаления, новые классы; потребители — в том же PR.
5. **App Shell** — шапка, сайдбар (вложенность, свёртывание, пользователь + выход),
   футер по `docs/design-system/screens/App-Layout.html` и листу DS-Layout.
6. **Tailwind в новом слое** — по §1.2 и `docs/design-system/tailwind/`.
7. *(позже)* Тёмная тема.

## 6. Открытые вопросы (до решения — как сейчас в коде)

1. **Деньги.** Лист DS-Money v3.4 требует тонкий пробел U+2009 и цветные расходы;
   в коде (`vf_money`) — U+00A0 и «расход — обычный цвет». U+2009 переносится по
   строке, для сумм это ошибка; рекомендация — оставить U+00A0 и поправить лист.
   Цвет расходов — решение Владельца. **До решения форматирование денег не менять.**
2. **Вкладки-разделы** (`.page-tabs`, подчёркивание, переходы между роутами) в ките
   v3.4 не описаны. До решения — оставить как есть с `TODO(ds)`.
3. **Кегль легаси.** Body v3.4 — 13px, легаси сейчас 14px (Inter 14/20). Рекомендация —
   оставить 14px в легаси до перевода экранов на новый слой.
4. **Палитра графиков** — в ките нет; `--vf-chart-*` без изменений, `TODO(ds)`.

## Changelog

- 2026-11-15: документ создан (handoff ДС v3.4).
