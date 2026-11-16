# @vashfindir/ds — v3.4, Stage 1

Общая ДС для account/admin. Runtime exports: `.` (tokens и TokenName),
`./tokens.css` (`:root` с `--ds-`), `./styles.css` (пока только tokens.css;
Tailwind/preflight и подключение apps — Stage 2). Тёмная тема не включена.
Токены не подключены к runtime приложений на Stage 1.

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
новые production Docker/CI изменения на Stage 1 не требуются. Проверка генератора
пока отдельная; включение её в production build — Stage 2.

Деньги: U+00A0, U+2212, запятая, ₽ суффиксом, расходы обычным цветом.
Иконки: @tabler/icons-react, размеры 16/18/20, stroke 2 (реализация позднее).
