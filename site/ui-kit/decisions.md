# Design System Decisions — Ваш Финдир

Канонический источник правды для дизайн-системы. Источник истины — `UIKit.html` v1.1.1.

---

## Tokens (CSS variables)

### Colors — Brand
```
--color-primary: #1A56DB;            /* основной бренд-цвет, primary CTA */
--color-primary-hover: #1E40AF;      /* hover/active primary */
--color-primary-tint: #EEF3FE;       /* фон выбранных пунктов, primary-hover bg */
--color-primary-border: #C7D7FB;     /* primary-border для tint-блоков */
--color-primary-selection: #DBE6FF;  /* ::selection */
```

### Colors — Text
```
--text-strong: #0B1220;     /* основной текст */
--text-secondary: #475569;  /* вторичный */
--text-tertiary: #64748B;   /* caption, labels */
--text-muted: #8B95A7;      /* плейсхолдеры, meta */
--text-disabled: #94A3B8;   /* disabled, минимальная mета */
--text-faint: #CBD5E1;      /* нулевые суммы, маркеры */
--text-inverse: #FFFFFF;    /* текст на тёмных подложках */
```

### Colors — Borders & Backgrounds
```
--border: #E8ECF3;          /* единая граница cards/inputs */
--border-soft: #F1F4F9;     /* мягкие row-дивайдеры */
--bg-page: #F4F6FA;         /* фон страницы */
--bg-card: #FFFFFF;         /* карточки */
--bg-subtle: #F7F9FC;       /* workspace switcher, condition rows */
--bg-row-hover: #FAFBFD;    /* thead, hover на строке */
--bg-row-selected: #F4F8FF; /* выбранная строка */
--bg-dark: #0B1220;         /* hero-карточки */
```

### Colors — Semantic
```
--success-bg: #ECFDF5;  --success-border: #D1FAE5;  --success-text: #047857;  --success-dot: #10B981;
--danger-bg:  #FEF2F2;  --danger-border:  #FECACA;  --danger-text:  #B91C1C;  --danger-dot:  #EF4444;
--warning-bg: #FFFBEB;  --warning-border: #FDE68A;  --warning-text: #92400E;  --warning-dot: #F59E0B;
--neutral-tint-bg: #F4F6FA;  --neutral-tint-text: #475569;
```

### Colors — Categories (8 фиксированных)
```
--cat-revenue: #10B981;     /* доходы */
--cat-marketing: #F59E0B;   /* реклама */
--cat-services: #06B6D4;    /* услуги, аренда */
--cat-payroll: #8B5CF6;     /* зарплата */
--cat-tax: #EF4444;         /* налоги */
--cat-software: #0EA5E9;    /* связь, ПО */
--cat-returns: #94A3B8;     /* возвраты */
--cat-financial: #1A56DB;   /* финансовая деятельность */
```

### Colors — Banks (бренд-токены)
```
--bank-tinkoff: #FFDD2D / fg #0B1220
--bank-sber:    #1A9F29 / fg #fff
--bank-alfa:    #EF3124 / fg #fff
--bank-tochka:  #6132D6 / fg #fff
--bank-mts:     #EF4444 / fg #fff
--bank-yookassa:#7B61FF / fg #fff
```

### Colors — Source badges (5 фикс. наборов: bg/text/border)
```
--src-api-*      /* синий — импорт из API */
--src-manual-*   /* серый — ручная операция */
--src-bot-*      /* фиолетовый — операция из бота */
--src-xls-*      /* зелёный — XLS-импорт */
--src-1c-*       /* оранжевый — 1С */
```

### Typography
```
--font-family: 'Manrope', system-ui, -apple-system, 'Segoe UI', sans-serif;
--font-mono: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, monospace;
--font-9_5:  9.5px;   /* uppercase микро-капсы (РАЗДЕЛ, ОСНОВНОЙ) */
--font-10_5: 10.5px;  /* table head, section labels */
--font-11:   11px;    /* footer hint, KPI meta */
--font-11_5: 11.5px;  /* form field label, KPI label */
--font-12:   12px;    /* filter chip, secondary */
--font-12_5: 12.5px;  /* tabs, compact buttons, sidebar item */
--font-13:   13px;    /* body, table row, input value */
--font-13_5: 13.5px;  /* sidebar main, subtotal */
--font-14:   14px;    /* card section header, money 14 */
--font-15:   15px;    /* modal title */
--font-17:   17px;    /* drawer header title */
--font-22:   22px;    /* KPI compact, balance */
--font-28:   28px;    /* KPI hero, page title */
```

### Spacing — 4-base
```
--s-1: 4px;   --s-2: 8px;   --s-3: 12px;  --s-4: 16px;
--s-5: 20px;  --s-6: 24px;  --s-7: 32px;  --s-8: 40px;
```

### Radius
```
--r-1: 4px;        /* chips, badges */
--r-2: 5px;        /* nav items, small buttons */
--r-3: 6px;        /* inputs, primary buttons */
--r-4: 8px;        /* cards, sections */
--r-pill: 999px;   /* status pill, toggle */
--r-circle: 50%;   /* avatar, dots */
```

### Shadows
```
--shadow-sm:          0 1px 2px rgba(15,23,42,.06)              /* active tab, pressed pill */
--shadow-card-hover:  0 4px 12px rgba(15,23,42,.04)             /* hover на card-row */
--shadow-primary:     0 1px 0 #fff/15 inset, 0 4px 10px primary/.25  /* primary CTA */
--shadow-menu:        0 16px 36px + 0 2px 8px rgba(15,23,42,.14) /* dropdown row menu */
--shadow-modal:       0 24px 60px + 0 4px 12px rgba(11,18,32,.28) /* modal */
--shadow-drawer:      -20px 0 60px rgba(11,18,32,.22)            /* slide-in drawer */
```

### Layout
```
--card-padding: 16px 20px;             /* ЕДИНЫЙ padding всех карточек/секций */
--drawer-width: 680px;                 /* единый drawer */
--drawer-padding-body: 24px 28px;
--modal-width-compact: 440px;          /* выбор компании */
--modal-width-standard: 520px;         /* контрагент, категория */
--backdrop-opacity: 0.45;
--backdrop-blur: 6px;
--avatar-sm: 24px;  --avatar-md: 32px;  --avatar-lg: 40px;
--toggle-w: 42px;  --toggle-h: 24px;  --toggle-knob: 20px;
```

---

## Components

- **Button** (`.btn`) — действия. Variants: `.btn-primary` / `.btn-secondary` / `.btn-ghost` / `.btn-danger` × Sizes: `.btn-sm` (30) / `.btn-md` (36) / `.btn-lg` (44). States: hover / focus / active / disabled / loading.
- **Input** (`.input`) — текстовые поля, высота 38. Wrap `.field` + `.field-label` + `.field-helper`. Prefix/suffix через `.input-wrap`.
- **Money** (`.money`) — суммы. См. **Money rules** ниже.
- **Filter chip** (`.chip.chip--filter`) — переключаемые табы фильтра.
- **Source badge** (`.src.src--api|manual|bot|xls|1c`) — источник операции.
- **Bank badge** (`.bank-mark.bank-mark--tinkoff|sber|alfa|tochka|mts|yookassa`) — бренд-плашка банка.
- **StatusPill** (`.status.status--success|danger|warning|neutral|pending`) — статус. **Везде pill + точка**, без отдельных dot+text.
- **Avatar** (`.avatar.avatar--sm|md|lg` + `.avatar--square` для компаний) — три размера, четыре tint-варианта.
- **Toggle** (`.toggle.is-on|is-disabled`) — 42×24, knob 20.
- **Table row** (`.t-table .t-row--hover|selected|day|subtotal`) — таблица с day-divider и subtotal-row.
- **KPI card** (`.kpi` + `.kpi-value` / `.kpi-value--hero` для 28px) — две шкалы.
- **Account card** (`.acc-card`) — карточка счёта/кассы, баланс 22px нейтральный.
- **Card** (`.cb` в storybook'е, любая карточка с border + padding 16×20) — базовый блок.
- **Dropdown menu** (`.menu` + `.menu-item` / `.menu-item.is-primary` / `.menu-item.is-danger`) — действия. **Иконки наследуют currentColor**, без эмодзи.
- **Tabs** (`.tabs .tab.is-active`) — segmented, padding 3, активный с тенью.
- **Drawer** (`.dw` + `.dw-head` / `.dw-body` / `.dw-foot`) — 680px справа, body на сером, footer sticky.
- **Modal** (`.mdl` / `.mdl--compact`) — 440 или 520.
- **Empty state** (`.empty` + `.empty-icon` / `.empty-title` / `.empty-text`) — карточка с одним CTA.
- **Direction indicator** (`.direction.direction--in|out|neutral`) — цветной dot + текст. Без кружков ↑↓ и +/− чипов.
- **Sidebar** (`.sb` + `.sb-head` / `.sb-nav` / `.sb-item.is-active` / `.sb-sub` / `.sb-foot`) — 264px, logo + workspace + группы + footer.
- **EntityPicker** (`.mdl + .picker + .picker-item`) — **универсальный плоский** выбор. Один компонент для: контрагент / проект / компания. Не создавай отдельных пикеров.
- **TreePicker** (`.mdl + .tree-node + .picker-breadcrumb`) — иерархический выбор. Разделы `.is-disabled` (некликабельны), только листья выбираемы. Breadcrumb внизу.
- **Tags** (`.tags-input + .tag-chip` + `.suggest`) — нейтральные чипы, autosuggest с опцией «Создать».

---

## Money rules

| # | Формат | Пример | Размер | Вес | Цвет |
|---|---|---|---|---|---|
| 01 | Signed transaction | `+245 000 ₽` / `−156 200 ₽` | 14px | 700 | success-text / danger-text |
| 02 | Balance unsigned | `3 829 930 ₽` | 22px | 700 | text-strong (нейтральный) |
| 03 | Short metric (M/K) | `2,0 М ₽` / `500 К ₽` | 10–14px | 500–700 | text-strong |
| 04 | Foreign currency | `$8 450` / `€12 200` | 14–22px | 700 | text-strong |
| 05 | No currency (col-context) | `1 245 230` | 13–14px | 500–700 | text-strong |
| 06 | Inline meta | `в обработке: 18 200 ₽` | 11.5px | 500–600 | warning-text / text-muted |
| 07 | Input value (raw) | `95 000` | 13–18px | 700 | text-strong (без знака) |
| 08 | Zero state | `0` / `— ₽` | 14px | 500 | text-faint (#CBD5E1) |
| 09 | Percentage delta | `+12,4%` / `−4,1%` | 10.5px | 700 | delta-up / delta-down chip |
| 10 | Day total / subtotal | `+206 500 ₽` / `Всего: 3 079 930` | 11.5–13.5px | 700 | success/danger (signed) или text-strong (итог) |
| 11 | Rate / annualized | `+12% год.` / `ставка 1,99%` | 11px | 600–700 | success-text (для дохода) |
| 12 | Hero balance (dark) | `3 829 930 ₽` | 28px | 700 | #fff на --bg-dark |

**tabular-nums** обязательны для всех числовых ячеек таблиц и любого блока сумм.

### Must-rules
1. Знак минуса — **U+2212 (`−`)**. Hyphen-minus (`-` U+002D) запрещён в форматах сумм.
2. Разделитель разрядов — **тонкий пробел U+2009** (`&thinsp;`).
3. **₽ суффиксом** (после числа, с пробелом). `$` / `€` — префиксом без пробела.
4. Десятичные — **запятая** `,`. Только в коротких форматах (`М`/`К`) и процентах.
5. **«М», не «млн»** — короткая запись для миллионов.
6. **Цвет — только для дельт и signed transactions**. Балансы, итоги, остатки на конец — нейтральные (text-strong).

---

## Decisions (top-15)

1. **Card padding**: `16px 20px` (единый, отменили 14×16 и 18×20).
2. **KPI value size**: `22px (compact)` / `28px (hero, dark)`. 26 удалён.
3. **Border color**: `#E8ECF3` (единый для cards + inputs). `#E2E8F0` удалён.
4. **Drawer width**: `680px` (поддерживает двухколоночные формы).
5. **Backdrop**: `opacity 0.45 · blur 6px` для всех модалок и drawer'ов.
6. **Avatar sizes**: `24 / 32 / 40` (sm/md/lg). Прежние 28/30/34 убраны.
7. **Direction indicator**: **colored dot + знак суммы**. Никаких ↑↓ кружков и +/− чипов.
8. **Status**: **pill с точкой** везде (operations, accounts, sync, rules). Не использовать голый dot+text.
9. **Money — знак минуса**: `U+2212 (−)` везде. Hyphen-minus запрещён.
10. **Money — миллионы**: `М` (не `млн`). `К` для тысяч.
11. **Money — цвет**: только для дельт и signed transactions. Балансы — нейтральные.
12. **Tint chip**: **именованные токены** (`--src-api-bg`, `--success-bg`). Не считать через `color + opacity`.
13. **Drawer body padding**: `24px 28px` (PROPOSED).
14. **tabular-nums** дефолт для всех денежных ячеек (PROPOSED).
15. **Icon color rule**: иконки наследуют `currentColor`. Окрашивается **весь пункт** по semantic роли (default / primary / danger), не иконка отдельно. **Эмодзи-иконки запрещены.**

---

## Don'ts

- ❌ Не вводи произвольных hex вне токенов. Если нужного нет — добавь в `:root` и документируй.
- ❌ Не создавай отдельных пикеров для контрагент / проект / компания — используй один **EntityPicker** с тремя инстансами.
- ❌ Не делай отдельный picker для иерархии — используй **TreePicker** с `.is-disabled` разделами.
- ❌ Не раскрашивай иконки в меню отдельно — currentColor. Окрашивай весь пункт по semantic роли.
- ❌ Не используй эмодзи как иконки (✏️ 📋 🗑 ⭐ и т. д.). Только SVG.
- ❌ Не используй hyphen-minus `-` в форматах денег. Только `−` U+2212.
- ❌ Не раскрашивай балансы и итоги в зелёный/красный. Цвет — только для дельт и signed transactions.
- ❌ Не используй goldfish-радужные теги. Tags — нейтральные (`--bg-page` + `--border`).
- ❌ Не создавай новые шкалы шрифта/спейсинга вне 4-base. Используй существующие токены.
- ❌ Не делай Direction чипами `+/−` или кружками ↑↓. Только colored dot + текст.
- ❌ Не используй sync-статусы как голый dot+text. Везде StatusPill.
- ❌ Не делай Card с padding 14×16 или 18×20. Только 16×20.
- ❌ Не вводи 26px KPI. Только 22 (compact) или 28 (hero/dark).
- ❌ Не пиши «млн ₽» в коротких форматах. Только `М ₽`.
- ❌ Не разделяй тысячи обычным пробеблом — только U+2009 (`&thinsp;`).
- ❌ Не помещай родительские категории в выбор — только листья (leaf) кликабельны в TreePicker.
- ❌ Не делай отдельных drawer'ов на 640px. Стандарт — 680.
