# @vashfindir/ds — v3.4, Stage 4

Общая ДС для account/admin. Runtime exports: `.` (tokens, TokenName и React-компоненты),
`./tokens.css` (`:root` с `--ds-`), `./styles.css` (Tailwind v4 + preflight,
тема ДС, Manrope, базовые стили текущих экранов). Тёмная тема не включена.

App CSS entry импортирует `@vashfindir/ds/styles.css` и объявляет `@source './'`
для собственного src; main.tsx подключает этот entry. Пакет отключает
автопоиск Tailwind и добавляет только `./components`; соседний app, tests и
legacy site не сканируются. Vite использует `@tailwindcss/vite`.

Button/Link/FormField/Input/InputGroup/Textarea/Select/Checkbox/Radio/Toggle,
Badge/StatusPill/Card/EmptyState/Alert/Toast и ErrorBoundary живут здесь.
Apps больше не зависят от старого UI. Псевдоклассы
focus-visible/hover/disabled/error — поведение браузера, без class-моков.
Manrope 200–800 подключён локально, кириллица/латиница; OFL доставляется через
общий Vite publicDir по `/fonts/Manrope-OFL.txt`. Никаких runtime-запросов шрифта
к внешним сервисам. `prebuild` apps проверяет актуальность generated токенов.

## Источник и обновление

`tokens/ds-v3.tokens.json` — **точная копия**
`docs/design-system/v3/tokens/ds-v3.tokens.json` из PR #3022,
commit `3882c5dd9238005d16ba661fea78024400da45f8`.
Сначала обновляется эталон, затем копия, затем generated output.
`tests/fixtures/light-css-values.json` — независимый снимок всех переменных
первого светлого `:root` из `v3/sheets/ds-kit.css` того же commit. Не получен
из JSON токенов или генератора. Он позволяет проверить CSS-эталон даже до
merge PR #3022 и не требует Git/network во время теста. Обновляется вместе
с выгрузкой эталона; сначала проверить новые значения при ревью.

Style Dictionary нормализует группы primitive/semantic/component/deprecated в
плоские имена, сохраняя исходный JSON и ссылки `{name}`. CSS сохраняет граф
через `var(--ds-name)`, TS экспортирует разрешённые строковые значения и
`TokenName`. Подчёркивания в `s-0_5` и deprecated именах сохраняются один к одному.
Значения не преобразуются стандартным css transformGroup: источник уже содержит
CSS-строки (в том числе shadow/типографику), его $type местами неточен.

`src/tokens.css` и `src/tokens.ts` коммитятся: потребителю не нужен генератор
или build tools в runtime. `npm run check:generated` собирает во временный
каталог, побайтно сравнивает оба файла и удаляет только свой временный каталог.
Нет timestamp или зависимости результата от рабочей директории.

## Проверки в Node-контейнере

Используется существующий Vitest/TypeScript account, без второй копии tooling.
Сначала `make front-install` из корня. Зависимость генератора и lock пакета
устанавливаются отдельно; все команды ниже из корня:

```sh
docker compose -p vf-finance-frontend -f docker-compose.frontend.yml run --rm \
  --no-deps --user "$(id -u):$(id -g)" -w /workspace/apps/packages/ds account npm ci
docker compose -p vf-finance-frontend -f docker-compose.frontend.yml run --rm \
  --no-deps --user "$(id -u):$(id -g)" -w /workspace/apps/packages/ds account npm run build
docker compose -p vf-finance-frontend -f docker-compose.frontend.yml run --rm \
  --no-deps --user "$(id -u):$(id -g)" -w /workspace/apps/packages/ds account npm run check:generated
docker compose -p vf-finance-frontend -f docker-compose.frontend.yml run --rm \
  --no-deps --user "$(id -u):$(id -g)" -w /workspace/apps/packages/ds account npm test
docker compose -p vf-finance-frontend -f docker-compose.frontend.yml run --rm \
  --no-deps --user "$(id -u):$(id -g)" -w /workspace/apps/packages/ds account npm run typecheck
make front-typecheck front-lint front-test front-build
```

Оба приложения включают тесты/типы/линт пакета в существующие команды;
`npm run build` также вызывает check:generated. Дополнительные compile-тесты
проверяют сброс default palette/type/radius/shadow/breakpoints и DS utilities.
Production Docker/CI integration применена после named approval §3.3.
`front-install` устанавливает пакет перед apps; Docker build копирует DS,
CI учитывает DS paths/lock и устанавливает пакет отдельно. История согласованного
patch — `docs/tasks/ds-v3-apps/proposals/`.

Деньги: U+00A0, U+2212, запятая, ₽ суффиксом, расходы обычным цветом.
Иконки: @tabler/icons-react3.52.0, только named imports16/18/20, stroke2;
IconPlus exported с ограниченным size и фиксированным stroke. Остальной каталог
реэкспортов расширяется в Stage5 по мере появления потребителей.

## Компоненты и доступность

React19 — peer dependency; приложения dedupe React через Vite. Нативные props
и ref передаются контролу. Button default type=button; loading также disabled.
IconOnly требует непустой aria-label; icon декоративный. Link принимает native
onClick/href для router adapter, external добавляет безопасные target/rel и
подпись новой вкладки.

FormField принимает один контрол, сохраняет его id или генерирует useId,
объединяет aria-describedby, helper/error IDs и required/aria-invalid. Разные
id у поля и контрола — ошибка. Error не навешивает произвольный role=alert на
каждое поле; общая ошибка формы выводится через Alert.

```tsx
<FormField id="amount" label="Сумма" helper="В рублях" error={error}>
  <InputGroup
    value={value}
    onChange={(e) => setValue(e.target.value)}
    prefix="₽"
    clearable
    onClear={() => setValue('')}
  />
</FormField>
```

Clearable — только controlled value/onClear; readOnly/disabled не очищаются.
Пароль использует native input без копирования значения и сохраняет defaultValue,
form reset и ref. Вставляемый action — доступная кнопка с aria-label.
Select/Checkbox/Radio/Toggle нативные по owner brief; popup select не добавлен.
Focus outline2px/offset2px сохраняется вместе с focus/error shadow листа.

Toast по умолчанию исчезает через4s, останавливает отсчёт при hover/focus,
чистит timer при unmount; onClose не вызывается повторно. duration<=0 отключает
таймер. На новое уведомление нужен новый key/mount, duration меняет время жизни.
Toast не перемещает фокус. Card не рассчитывает данные; EmptyState headingLevel
задаётся по контексту (404 — h1).

Vitest SSR-контракты запускаются командами обоих apps. Поведение DOM, keyboard,
ref, Toast и контраст — Chromium fixture `apps/account/tests/components`;
`npm run test:components` из account внутри Playwright-контейнера. Он также
вызывается через `posttest:e2e`: CLI args --config остаются у Playwright. Fixture собирается в test-results, в production dist
и маршруты не попадает.

Доступность: source component input-border-error ссылается на danger-dot,
дающий2.80:1 на белом. До исправления эталона error border использует уже
существующий semantic danger-text (≥3:1); TODO(ds) в CSS. Generated токены
не изменяются. DS tsconfig автономен: production admin не копирует account
и не должен загружать его tsconfig для трансформации React-компонентов.

Toast монтируется в единственном ToastStack у корня приложения, вне
transform/scroll panels: fixed top/right24px, gap10px, z-toast200, max-width
ограничен viewport. Контейнер исключает уведомления из потока страницы.

```tsx
<ToastStack>
  {messages.map((message) => (
    <Toast
      key={message.id}
      title={message.title}
      onClose={() => dismiss(message.id)}
    />
  ))}
</ToastStack>
```

Fixture runner сам выполняет npm ci --ignore-scripts для DS из lock перед
сборкой: matrix production browser runner устанавливает только app, а linked
file dependency не устанавливает транзитивные зависимости пакета. Проверка
чистой поставки выполняется с отдельным пустым DS node_modules.

## Каркас приложения

AppShell получает header/sidebar/footer/children и controlled collapsed с
onCollapsedChange. AppHeader и Sidebar монтируются внутри его context;
публичный useShell не экспортируется. Общий header60px, sidebar264/72,
бренд «ВФ»32px, content24×32 и footer12×32 повторяют App-Layout.

```tsx
const sidebar = useSidebarState('vf.account.sidebar');
return (
  <AppShell
    {...sidebar}
    header={
      <AppHeader brandLabel="Ваш Финдир" breadcrumbs={[{ label: 'Обзор' }]} />
    }
    sidebar={
      <Sidebar items={items} activeId="home" user={user} onLogout={logout} />
    }
    footer={<AppFooter>Ваш Финдир</AppFooter>}
  >
    <PageHeader title="Обзор" description="Описание страницы" />
    {children}
  </AppShell>
);
```

Native props/ref передаются корневым элементам. mainId связывает первый skip
link и focusable main. Sidebar groups — native disclosure buttons и вложенные
списки, без искусственного role=tree; activeId открывает ancestors. В rail
labels sr-only, submenus hidden, клик группы раскрывает и sidebar и subtree.
Collapse↔expand и автоматический responsive переход сохраняют видимый фокус.

useSidebarState хранит только expanded/collapsed UI preference; keys apps
раздельные vf.account.sidebar/vf.admin.sidebar. <1280 автоматически rail без
перезаписи desktop preference, storage failures не ломают UI.
Рекомендуется постоянный storageKey на протяжении жизни компонента.

AppShell владеет viewport, body не прокручивается; main и sidebar nav имеют
независимый scroll и stable gutter. Footer расположен после flex1 main content.
Header period/search/notifications/actions — слоты, отсутствующие функции
не рисуются. renderLink позволяет сохранить router click/history semantics.
Sidebar user.name берётся из реальной identity (email fallback), detail
опционален; профиль/роли не выдумываются. logoutBusy — только реальный pending
logout, не общий busy сессии: существующий logout должен прерывать refresh.

Public about/404 используют layout без SessionProvider; authenticated layout
потребляет текущий provider. API, CSRF и session store не меняются.
DropdownMenu/Avatar доступны; Sidebar переиспользует Avatar, user row/logout сохраняет layout.
Source group captions text-disabled заменены semantic text-tertiary для4.5:1.

Тестовый fixture имеет проекты components и layout, последний с обычными
scrollbars; проверяет реальную ширину gutter и отсутствие сдвига content.
Новые runtime routes, production config и зависимости для каркаса не нужны.

## Данные, форматтеры и оверлеи (Stage 5)

`Table` принимает native table props/ref, обязательный `caption` и обычные
`thead/tbody/tfoot`. `SortHeader` — `th` с direction `ascending/descending/null`,
`onSort` и align; sorting выполняет потребитель. `TotalsRow` — native tr,
итоги передаются готовыми. Для строк доступны `data-kind="day|subtotal"`,
`data-selected`, для ячеек `data-align="right"`. `Pagination` получает
page/pageSize/total/onPageChange: диапазон, first/last/ellipsis, безопасные границы.

`Tabs`: label, items `{id,label,count?,tone?,disabled?,content?}`, value/onChange;
selected panel ассоциирован по ID, стрелки/Home/End пропускают disabled.
`KpiCard`: label/value/meta/status, hero, delta `{value,tone:up|down|neutral}`.
Значение нейтральное; ДС не вычисляет деньги, проценты или благоприятность дельты.
`Chip`: selected/onSelect и отдельный onRemove/removeLabel; interactive элементы
не вкладываются друг в друга. `AbcPill`: revenue/profit A/B/C. `MarketplaceMark`:
ozon/wb/yamarket/megamarket, mark28 или inline16. `Avatar`: name/src, sm24/md32/lg40,
circle/square, initials/error fallback. `TagsInput`: label, controlled string[]
value/onChange, optional suggestions; trim/case-insensitive duplicate protection,
Enter/arrows/Escape/IME, two-step Backspace, native disabled/readOnly/ref.
`required` проверяет committed tags, незавершённый текст не удовлетворяет
обязательности; `name` сериализует каждую выбранную метку отдельным hidden
полем (повтор имени), сохраняя native form/disabled.

Форматтеры — только представление, не бухгалтерские вычисления:

| API                              | Контракт                                                                                                                                                                    |
| -------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `formatMoney(value, options?)`   | number/bigint/exact decimal string/null; по умолчанию2decimal, RUB; `fractionDigits:0` — явное отображение целых; `withCurrency:false` — валюта в заголовке; USD/EUR prefix |
| `formatNumber(value, options?)`  | до2decimal по умолчанию; `fractionDigits`, `sign:auto                                                                                                                       | always` |
| `formatPercent(value, options?)` | **процентные пункты**,12.4→12,4%;1decimal по умолчанию; ratio не пересчитывается                                                                                            |
| `formatDate(value, options?)`    | ISO date-only/qualified instant, Date или epoch milliseconds; timezoneUTC по умолчанию, опциональные timeZone/includeTime; calendar date не сдвигается                      |

Деньги: NBSP U+00A0, U+2212, comma, ₽ suffix; отсутствие — em dash,
нулевой результат округления без отрицательного знака. Intl обрабатывает decimal
strings без предварительного Number. Неверные/nonfinite числа, digits вне0–20,
неверные календарные даты и timezone-free timestamps выбрасывают RangeError.
Потребитель выбирает нужную точность и единицы; существующие финансовые формулы,
API и код форматирования site не меняются.

`Modal`: controlled open/onClose, title/description, size sm440/md520/lg640,
children/footer, initialFocusRef, dismissible/closeOnBackdrop/showClose и native
props/ref. `Drawer` использует тот же lifecycle и фиксированную ширину680 справа.
Native dialog.showModal() дает top layer/inert; Tab/Escape/restore, nested scroll
lock и cleanup проверяются браузером. Не передавайте native open attribute и
не вызывайте showModal вручную. `ConfirmDialog`: onConfirm, confirmLabel,
cancelLabel, busy/destructive; initial focus на cancel, busy блокирует повтор
и dismiss. Destructive использует существующую outline danger кнопку (без
красного solid background, как требует checklist).

`DropdownMenu`: controlled open/onOpenChange, trigger **строка подписи**,
triggerProps для Button, items `{id,label,onSelect,disabled?,tone?,icon?,shortcut?}`
или `{id,separator:true}`. Native popover top layer избегает clipping; позиция
вычисляется от trigger с viewport clamp. Arrow/Home/End/Enter/Space/Escape/Tab,
outside pointer, focus restore; callbacks — действия, не fake navigation.

`EntityPicker` и `TreePicker`: Modal props + open/onOpenChange, items,
value:string|null/onChange, searchable/allowClear. Каждый open начинает новый
draft; Cancel ничего не меняет, Apply подтверждает валидный выбор. Entity items:
id/label/meta/searchText/leading/trailing/disabled; дополнительные ReactNode
только presentation, без вложенных interactive controls. Tree items:
id/label/meta/searchText/disabled/children; наличие children означает branch,
даже пустой; branch раскрывается, выбирается только enabled leaf. IDs уникальны.
Search сохраняет ancestors, arrows/Home/End и Enter/Space управляют деревом,
query clear возвращает прежнюю expansion. Никаких запросов/скрытой server pagination.
Существующий account CompanyPicker сохраняет native Select и server store,
переиспользует только новый Pagination.

### Уведомления и верхний слой

Без открытого modal используйте root `ToastStack` вне scroll/transform panels.
TODO(ds): канон задаёт z-toast200/z-overlay100 и native dialog, но не задаёт
межслойную маршрутизацию глобальных уведомлений. Native top layer выше обычных
z-index, а фон inert. Для уведомлений активного окна передавайте существующий
`ToastStack` в `notifications` Modal/Drawer (вне scroll body); во вложенных окнах —
только в верхнее. Уведомление остаётся доступно для assistive technology и
клавиатуры. Автоматической глобальной маршрутизации нет. При dismiss уведомления
потребитель возвращает фокус, если удаляется текущий focused control.

Browser fixture `?advanced` собирается отдельно от production; native dialog и
popover проверяются существующим Chromium runner, новые зависимости не нужны.
