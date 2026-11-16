# @vashfindir/ds — v3.4, Stage 3

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
