# ДС «Ваш Финдир» v3.4 — внедрение в apps

Статус: решения Владельца 2026-11-15. Источник — UI Kit v3.4 (канвас Claude Design,
страница `ui-kit-v3.0`), выгрузка — `docs/design-system/v3/`. Задача для агента —
`docs/design-system/PROMPT-ds-v3.md`, приёмка — `docs/design-system/CHECKLIST-ds-v3.md`.

## 1. Решения Владельца (2026-11-15)

1. **Область — только `apps/`** (`apps/account`, `apps/admin`). `site/` не
   трогается: легаси, CSS кита `site/assets/styles/kit/`, `site/packages/*`, листы
   `docs/design-system/sheets/*.dc.html` и правила `site` в `CLAUDE.frontend.md`
   остаются как есть.
2. **Эталон визуала apps — UI Kit v3.4:** листы `docs/design-system/v3/sheets/DS-*.html`
   (обычный HTML) + `ds-kit.css`, токены `v3/tokens/*.tokens.json`, каркас
   `v3/screens/App-Layout.html`.
3. **Пакет ДС вне `site`:** `apps/packages/ds` (`@vashfindir/ds`) — токены, базовые
   стили, React-компоненты, форматтеры. Apps перестают импортировать
   `@vashfindir/ui/styles.css` (он тянет CSS кита из `site`). `site/packages/ui` не
   меняется и остаётся для `site`.
4. **Tailwind v4 в apps полностью** (с preflight): в apps нет Tabler. Тема Tailwind
   ссылается только на токены ДС — референс `v3/tailwind/app.css`. Компоненты — в
   пакете ДС, Tailwind в приложениях — раскладка и утилиты.
5. **Тёмная тема отложена:** `v3/tokens/ds-v3.dark.tokens.json` есть, в сборку пакета
   на этом этапе не входит; ни `[data-theme="dark"]`, ни
   `@media (prefers-color-scheme: dark)` в CSS apps.

Следствие: до переезда `site` на пакет ДС у продукта две реализации визуала (`site`
на ките v2.x, apps на v3.4). Это принято сознательно.

## 2. Пакет `@vashfindir/ds`

Расположение `apps/packages/ds` выбрано, потому что `./apps` уже смонтирован в
контейнеры `docker-compose.frontend.yml`; путь вне `apps/` потребовал бы новых
монтирований. Phase 0 проверяет, как apps собираются в прод-образ, и подтверждает
или меняет место.

```
apps/packages/ds/
  package.json            # "name": "@vashfindir/ds", exports: ".", "./tokens.css", "./styles.css"
  tokens/                 # ds-v3.tokens.json (копия из docs/design-system/v3/tokens, источник правды)
  build/tokens.mjs        # Style Dictionary: JSON → dist/tokens.css (prefix "ds"), dist/tokens.ts
  src/
    tokens.css            # сгенерирован: :root { --ds-… }
    styles.css            # Tailwind v4 entry по v3/tailwind/app.css (preset приложений)
    components/<Name>/    # <Name>.tsx, <Name>.test.tsx, index.ts
    format/               # formatMoney, formatNumber, formatPercent, formatDate
    icons/                # реэкспорт выбранного набора иконок
    index.ts
```

- Токены выпускаются с префиксом `--ds-` (токен листа `--text-tertiary` = `--ds-text-tertiary`):
  так имена темы Tailwind (`--color-*`, `--radius-*`) не пересекаются с токенами.
- Слои токенов — как в листе DS-Tokens: primitive → semantic → component. Компоненты
  используют только semantic/component; хардкод цветов запрещён.
- Компоненты стилизуются утилитами Tailwind на токенах; CSS-классы кита (`.btn`,
  `.card` …) из листов — эталон вида, не обязательные имена классов.
- Подключение в приложении: `import '@vashfindir/ds/styles.css'` в `main.tsx`
  вместо `@vashfindir/ui/styles.css`; ESLint/Prettier приложений расширить на
  `apps/packages/ds`.

## 3. Компоненты

### 3.1. Есть в ките v3.4 — реализовать в пакете

API (пропсы, варианты, состояния, клавиатура) определяет Phase 0 по листам; ниже —
минимум.

| Компонент | Лист | Минимальный API |
|---|---|---|
| Button | DS-Controls | `variant: primary \| secondary \| ghost \| danger`, `size: sm \| md \| lg`, `icon`, `iconOnly` (= `.btn-icon`, обязателен `aria-label`), `loading`, `disabled` |
| Link | DS-Controls | `variant: inline \| standalone \| muted`, `external` (иконка ↗, `rel="noopener"`, подпись для скринридера); только навигация — действие на месте всегда Button (Decision 47) |
| FormField | DS-Controls | `label`, `required`, `helper`, `error`, связывает `id`/`aria-describedby` |
| Input, Textarea | DS-Controls | состояния default / hover / focus / error / disabled |
| InputGroup («Поле с дополнением») | DS-Controls | `prefix`, `suffix`, `icon`, `action` (`clearable`, показать пароль) |
| Select, Checkbox, Radio, Toggle | DS-Controls | нативные элементы со стилями ДС |
| TagsInput | DS-Controls | |
| EntityPicker, TreePicker | DS-Controls | модалка + список/дерево, выбор только листа в дереве |
| Badge/Chip, StatusPill, ABC-pill, MarketplaceMark, Avatar | DS-Data | |
| KpiCard, Card | DS-Data | цвет только у дельты |
| Table (+ SortHeader, TotalsRow, Pagination) | DS-Table | пагинация — компонент кита (Decision 46) |
| Tabs | DS-Tabs | сегментер, счётчики |
| Toast, Alert, EmptyState, ConfirmDialog | DS-Feedback | |
| Modal (S/M/L), Drawer (680) | DS-Overlays | фокус-ловушка, Esc, возврат фокуса, `aria-modal` |
| AppShell, AppHeader (+ крошки), Sidebar, DropdownMenu, PageHeader | DS-Layout | по `v3/screens/App-Layout.html` |

### 3.2. Нет в ките — дорисовать в ките до реализации (P1)

Порядок: сначала лист в UI Kit (решение Владельца), потом код. До листа —
`TODO(ds)` и ближайшее решение по существующим компонентам.

1. Выбор периода и даты (календарь + пресеты; поведение — как `PeriodFilter` в `site`).
2. Состояния загрузки: скелетоны, спиннер.
3. Состояния страницы: ошибка загрузки с повтором, 403, 404, нет доступа к компании.
4. Tooltip, Popover.
5. Combobox (поиск в поле с выпадающим списком).
6. Формы: ошибки сервера, блокировка при отправке, несохранённые изменения.
7. Экраны входа и регистрации. **Вход — готов:** `docs/design-system/v3/screens/Login.html` (тексты — `AUTH-SPEC.md`). Регистрация — по `AUTH-SPEC.md` §5 в визуале v3.4, лист — позже.
8. Таблица: выбор строк, панель массовых действий, фильтры колонок.

### 3.3. P2

Палитра графиков; Coverage matrix для `apps/admin`, если туда переедет мониторинг
загрузок (из кита v3.4 удалена как внутренняя); правила ширины (десктоп от 1280px,
ниже — рейка сайдбара, мобильные не поддерживаются); тёмная тема.

## 4. Решения, нужные до экранов с суммами и иконками

1. **Деньги** — один `formatMoney` в пакете (`Intl.NumberFormat('ru-RU')` + правила
   листа DS-Money: минус U+2212, запятая, «₽» суффиксом). Лист требует тонкий
   пробел U+2009 — он переносится по строке; **рекомендация — неразрывный пробел**
   (U+00A0, как в `site`) и поправить лист. Цвет расходов (лист: знаковые суммы
   цветные; `site`: расход обычным цветом) — **решение Владельца**. До решения —
   U+00A0, расход обычным цветом, `TODO(ds)`.
2. **Иконки** — один набор; рекомендация `@tabler/icons-react` (ближе к продукту),
   размеры 16 / 18 / 20, stroke 2. Инлайн-SVG из листов — эталон формы, не код.

## 5. Этапы

Каждый этап — отдельный PR, «один коммит — одна задача». Проверка — Vitest,
Playwright, скриншоты страниц apps в 1440.

1. **Пакет и токены:** `apps/packages/ds`, Style Dictionary, `tokens.css` с `--ds-`,
   `tokens.ts`; тест: каждый semantic-токен листа есть в `tokens.css`.
2. **Tailwind v4 в apps:** `styles.css` пакета по `v3/tailwind/app.css`, подключение
   в обоих приложениях, отказ от `@vashfindir/ui/styles.css`, шрифт Manrope;
   существующие страницы apps (вход, главная, about, 404) визуально — по v3.4.
3. **Базовые компоненты:** Button, Link, FormField, Input, InputGroup, Textarea,
   Select, Checkbox, Radio, Toggle, Badge, StatusPill, Card, EmptyState, Alert, Toast;
   перевод существующих экранов apps на них; `Button`/`EmptyState` из
   `@vashfindir/ui` в apps больше не используются.
4. **Каркас:** AppShell, AppHeader, Sidebar (вложенность, свёртывание, пользователь +
   выход), футер — в обоих приложениях (меню admin — своё).
5. **Данные и оверлеи:** Table + пагинация, Tabs, KpiCard, Modal, Drawer,
   ConfirmDialog, DropdownMenu, EntityPicker, TreePicker, форматтеры.
6. **P1 из §3.2** — по мере появления листов.
7. *(позже)* Тёмная тема.

## 6. Риски

- Две реализации визуала до переезда `site`: правки ДС делать в UI Kit и пакете;
  кит `site` не догоняет v3.4 автоматически.
- `apps` сейчас линтят `site/packages` — после переезда на пакет ДС убрать
  зависимость от `@vashfindir/ui`, если в apps не останется потребителей.
- Префикс `--ds-` расходится с именами в листах — правило соответствия записано в
  `v3/README.md` и в пакете.

## Changelog

- 2026-11-15: документ создан; в тот же день перенацелен с `site` на `apps`.
