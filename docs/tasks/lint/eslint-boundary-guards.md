# ESLint Boundary Guards — защита границ карантина

> Один PR. Добавляет ESLint-правила, запрещающие импорты из `_legacy/` в новый код.
> Без правок логики, без apt-fixes стилей.

---

## Цель

Граница карантина сейчас задокументирована, но не enforced. Этот PR делает её машинной:

- **Никто, кроме `assets/react/entrypoints/`** (когда появится) **не может импортировать из `_legacy/`.**
- **`_legacy/` не может импортировать из новых директорий** (`modules/`, `ui-kit/`, `shared/` — когда появятся). Карантин не «питается» новым кодом.
- CI блокирует merge при нарушении.

Правила работают **превентивно**: модули `assets/react/modules/` ещё не существуют, но как только появится первый файл с импортом из `_legacy/` — линтер упадёт.

---

## Pre-flight

1. `git status` чистый. Если нет — 🛑 STOP.
2. На свежем `master` (или основной ветке).
3. Прочитать `site/package.json`. Зафиксировать:
   - есть ли уже `eslint` в `devDependencies`
   - есть ли уже `@typescript-eslint/parser`, `eslint-plugin-import`, `eslint-import-resolver-typescript`
   - есть ли `lint` в `scripts`
   - менеджер пакетов: `npm` или `yarn` (по наличию `package-lock.json` / `yarn.lock`)
4. Прочитать `site/tsconfig.json`. Зафиксировать `compilerOptions.paths` (нужно для resolver).
5. Найти существующие конфиги ESLint: `.eslintrc*`, `eslint.config.js`, `eslint.config.mjs`. Если есть — изучить, **не перезаписывать**, только дополнять.
6. Найти CI-конфиг: `.github/workflows/*.yml` или `.gitlab-ci.yml`. Зафиксировать какой именно.

Если ничего из CI не нашлось — добавление в CI пропускается, отметить в финальном отчёте «CI не найден, ручная настройка нужна».

---

## Phase 1 — Установка зависимостей (если нужно)

В `site/`. Используй менеджер, который определён в pre-flight.

Необходимые пакеты (`devDependencies`):

```
eslint                                 (если ещё нет)
@typescript-eslint/parser              (если ещё нет)
eslint-plugin-import                   (если ещё нет)
eslint-import-resolver-typescript      (если ещё нет)
```

Команды (для npm):
```bash
cd site
npm install --save-dev \
  eslint \
  @typescript-eslint/parser \
  eslint-plugin-import \
  eslint-import-resolver-typescript
```

Для yarn:
```bash
cd site
yarn add --dev \
  eslint \
  @typescript-eslint/parser \
  eslint-plugin-import \
  eslint-import-resolver-typescript
```

**Если все пакеты уже стоят — установку пропустить.** Запустить только если хотя бы одного нет.

🛑 **STOP** перед `npm install`/`yarn add` — это новые зависимости. Если Владелец не одобрил установку явно (триггер задачи = одобрение), показать список устанавливаемых пакетов и подождать подтверждение в этом же запуске.

---

## Phase 2 — ESLint конфиг

### Если конфига ESLint **нет** в проекте

Создать `site/.eslintrc.cjs`:

```js
/* eslint-env node */
module.exports = {
  root: true,
  parser: '@typescript-eslint/parser',
  parserOptions: {
    ecmaVersion: 2022,
    sourceType: 'module',
    ecmaFeatures: { jsx: true },
  },
  plugins: ['import'],
  settings: {
    'import/resolver': {
      typescript: {
        project: './tsconfig.json',
      },
    },
  },
  rules: {
    // Граница карантина — главное правило этого PR
    'import/no-restricted-paths': ['error', {
      zones: [
        // Никто кроме entrypoints не импортирует из _legacy/
        {
          target: './assets/react/modules',
          from: './assets/react/_legacy',
          message: 'Не импортировать из _legacy/. Перенесите нужное в modules/ или shared/ как часть миграции.',
        },
        {
          target: './assets/react/ui-kit',
          from: './assets/react/_legacy',
          message: 'UI Kit не зависит от _legacy/. Никогда.',
        },
        {
          target: './assets/react/shared',
          from: './assets/react/_legacy',
          message: 'shared/ не зависит от _legacy/. Если нужна утилита из legacy — перепишите её в shared/ заново.',
        },
        // _legacy/ не питается новым кодом
        {
          target: './assets/react/_legacy',
          from: './assets/react/modules',
          message: '_legacy/ заморожен. Не добавляйте импорты из modules/ в legacy-код.',
        },
        {
          target: './assets/react/_legacy',
          from: './assets/react/ui-kit',
          message: '_legacy/ заморожен. Не добавляйте импорты из ui-kit/ в legacy-код.',
        },
      ],
    }],
  },
  ignorePatterns: [
    'node_modules/',
    'public/build/',
    'vendor/',
    '*.config.js',
    '*.config.mjs',
    '*.config.cjs',
  ],
};
```

### Если конфиг ESLint **уже есть**

Дополнить существующий конфиг:

1. В `plugins` добавить `'import'`, если ещё нет.
2. В `settings['import/resolver']` добавить `typescript: { project: './tsconfig.json' }`, если ещё нет.
3. В `rules` добавить блок `'import/no-restricted-paths'` из шаблона выше **целиком**.
4. Если в существующем конфиге уже есть `'import/no-restricted-paths'` — слить zones в один массив, не перезаписывать чужие правила.

Никаких других правил **не добавлять и не менять**. Этот PR строго про границы карантина.

---

## Phase 3 — npm script

В `site/package.json`, секция `scripts`:

```json
"scripts": {
  "lint": "eslint 'assets/**/*.{ts,tsx,js,jsx}' --max-warnings=0",
  "lint:boundaries": "eslint 'assets/**/*.{ts,tsx,js,jsx}' --rule '{\"import/no-restricted-paths\": \"error\"}' --no-eslintrc --parser @typescript-eslint/parser"
}
```

- Если `lint` уже определён — **не перезаписывать**. Добавить только `lint:boundaries`.
- Если `lint:boundaries` уже определён — оставить как есть.

---

## Phase 4 — Проверка работы

### 4.1. Положительный тест: текущая кодовая база проходит линтер

```bash
cd site
npx eslint 'assets/**/*.{ts,tsx,js,jsx}' --rule '{"import/no-restricted-paths": "error"}'
```

**Ожидаемо:** exit 0. Сейчас `modules/`, `ui-kit/`, `shared/` (вне `_legacy/`) пусты — нарушать нечего.

Если есть ошибки — это означает либо:
- В коде уже есть запрещённые импорты (например, кто-то в `assets/api/` импортирует из `_legacy/`).
- Конфиг неверен.

Разобраться, **не глуша** правило. Если найден реальный нарушитель — задокументировать в отчёте PR.

### 4.2. Негативный тест: правило действительно срабатывает

Временно создать файл `site/assets/react/modules/__test_boundary_check.ts`:

```ts
// Этот файл создан для проверки ESLint-правила и будет удалён.
import type { something } from '../_legacy/shared/format';
export const test = something;
```

Прогнать:
```bash
cd site
npx eslint assets/react/modules/__test_boundary_check.ts
```

**Ожидаемо:** exit 1, ошибка `import/no-restricted-paths` с сообщением про `_legacy/`.

После проверки **удалить** тестовый файл:
```bash
rm site/assets/react/modules/__test_boundary_check.ts
# Если создавалась директория modules/ только ради теста — удалить и её:
rmdir site/assets/react/modules 2>/dev/null || true
```

Если негативный тест **не дал** ошибку — правило не работает, 🛑 STOP, разбираться.

---

## Phase 5 — CI

### Если в проекте `.github/workflows/`

Найти основной workflow для frontend (например `frontend.yml`, `ci.yml`, `lint.yml`). Если нет специфичного — добавить новый `frontend-lint.yml`:

```yaml
name: Frontend Lint

on:
  pull_request:
    paths:
      - 'site/assets/**'
      - 'site/.eslintrc.cjs'
      - 'site/package.json'
      - 'site/package-lock.json'
      - 'site/yarn.lock'
  push:
    branches: [master]

jobs:
  eslint-boundaries:
    runs-on: ubuntu-latest
    defaults:
      run:
        working-directory: site
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'
          cache-dependency-path: site/package-lock.json
      - run: npm ci
      - run: npx eslint 'assets/**/*.{ts,tsx,js,jsx}'
```

Если используется yarn — поправить `cache: yarn`, `cache-dependency-path: site/yarn.lock`, `yarn install --frozen-lockfile`, `yarn eslint ...`.

Если есть существующий workflow с шагом lint — **добавить шаг туда**, не плодить новые файлы.

### Если в проекте `.gitlab-ci.yml`

Добавить job:

```yaml
frontend-lint:
  stage: test
  image: node:20
  before_script:
    - cd site
    - npm ci
  script:
    - npx eslint 'assets/**/*.{ts,tsx,js,jsx}'
  rules:
    - changes:
        - site/assets/**/*
        - site/.eslintrc.cjs
        - site/package.json
        - site/package-lock.json
```

### Если CI-конфига нет

Пропустить Phase 5. В отчёте PR явно отметить: «CI-конфиг не найден. Защита границ работает локально через `npm run lint`, требует ручной настройки в CI».

---

## Phase 6 — Commit + draft PR

```bash
cd ~/projects/app-service-finance
git checkout -b chore/eslint-legacy-boundaries

git add site/.eslintrc.cjs site/package.json site/package-lock.json
# (если устанавливались deps — добавляются lock-files)
# (если правился CI — добавить .github/workflows/* или .gitlab-ci.yml)

git status   # сверить: не более 4-5 файлов в staged

git commit -m "chore(lint): enforce _legacy/ import boundaries via ESLint

Adds import/no-restricted-paths rule preventing imports from
assets/react/_legacy/ into new code (modules/, ui-kit/, shared/),
and vice versa. Quarantine boundary is now enforced by CI."

git push -u origin chore/eslint-legacy-boundaries

gh pr create --draft \
  --title "chore(lint): enforce _legacy/ import boundaries" \
  --body "Adds ESLint boundary rules per CLAUDE.frontend.md.

## What this PR does
- Adds import/no-restricted-paths rule to ESLint config
- Adds npm run lint script (or extends existing one)
- Adds CI job that fails on boundary violations

## Verified locally
- [x] npx eslint passes on current codebase (0 violations)
- [x] Negative test confirmed: import from _legacy/ in test file triggers error
- [x] Test file deleted

## After merge
Any new code in assets/react/modules/, ui-kit/, shared/ that imports from _legacy/ will be blocked in CI."
```

Если `gh` недоступен — вывести URL для ручного создания PR.

---

## Self-review (перед STOP)

- [ ] `git status` чистый перед началом
- [ ] Зависимости установлены только если их не было
- [ ] ESLint конфиг создан или дополнен корректно
- [ ] `npm run lint` (или `npx eslint`) на текущем коде — exit 0
- [ ] Негативный тест прогнан, дал exit 1
- [ ] Тестовый файл удалён
- [ ] CI-шаг добавлен (или явно пропущен с пометкой)
- [ ] Коммит сделан, ветка запушена
- [ ] PR создан **в draft**
- [ ] Никакие другие ESLint-правила не добавлены (только `import/no-restricted-paths`)
- [ ] Никакие файлы в `_legacy/`, `api/`, `controllers/` не правились

---

## Что НЕ делать

```
не добавлять prettier                            — отдельная задача
не настраивать airbnb / standard / любой style   — этот PR только границы
не править существующие rules                    — оставить как есть
не запускать eslint --fix                         — никаких автофиксов
не править файлы в _legacy/                       — карантин заморожен
не мержить PR                                    — только Владелец
```

---

## STOP

🛑 Draft PR открыт. Ждать Владельца: ручной review, перевод в ready, merge.

После merge — граница карантина enforced. Можно идти в Шаг 2 (разбор `storybook.html` на UI Kit).
