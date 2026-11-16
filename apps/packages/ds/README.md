# @vashfindir/ds — v3.4, Stage 2

Общая ДС для account/admin. Runtime exports: `.` (tokens и TokenName),
`./tokens.css` (`:root` с `--ds-`), `./styles.css` (Tailwind v4 + preflight,
тема ДС, Manrope, базовые стили текущих экранов). Тёмная тема не включена.

App CSS entry импортирует `@vashfindir/ds/styles.css` и объявляет `@source './'`
для собственного src; main.tsx подключает этот entry. Пакет отключает
автопоиск Tailwind и добавляет только `./components`; соседний app, tests и
legacy site не сканируются. Vite использует `@tailwindcss/vite`.

Существующие Button/EmptyState/ErrorBoundary JS wrappers пока из старого UI
(замена — Stage 3), но все их CSS теперь из этого пакета по v3.4. Псевдоклассы
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
Production Docker/CI integration подготовлена proposal patch в
`docs/tasks/ds-v3-apps/proposals/`; применение — после named approval §3.3.

Деньги: U+00A0, U+2212, запятая, ₽ суффиксом, расходы обычным цветом.
Иконки: @tabler/icons-react, размеры 16/18/20, stroke 2 (реализация позднее).
