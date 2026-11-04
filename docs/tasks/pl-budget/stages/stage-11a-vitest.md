# Stage 11a: vitest для чистой логики сетки бюджета (отдельный PR)

**Risk:** LOW. Только dev-зависимость, скрипт, шаг CI и тесты; кода приложения
не меняет. Решение Владельца 04.11 (`docs/plan/ds-budget-ui.md` §5): vitest —
только для чистой логики сетки, отдельным небольшим PR.

## 1. Какую задачу решаем

На фронте нет тест-раннера (`CLAUDE.frontend.md`, «Гейты»). Логика острова
`budget-grid` — разбор ввода суммы, знак расходной статьи, сумма копейками,
форматирование, выбор разреза «проект / ЦФО» — проверяется только руками на
стенде. Этапы 12–15 будут менять эту логику; без тестов регрессии ловятся
поздно.

## 2. Что будет результатом

| Часть | Что |
|---|---|
| Зависимость | `vitest` в `devDependencies` (`yarn.lock`), версия под Vite 6 |
| Конфиг | `vitest.config.ts`: окружение `node`, алиас `@` как в `vite.config.js`, только `assets/**/*.test.ts` (без плагина Symfony) |
| Скрипт | `npm run test:unit` → `vitest run` |
| CI | шаг `yarn test:unit` в `frontend-lint.yml` (тот же job, после eslint); путь `vitest.config.ts` в триггерах |
| Тесты | `amount.test.ts` (разбор ввода, знак, `addAmounts`, `formatValue`, `formatPercent`), `slice.test.ts` (разрез по умолчанию, адрес, взаимное ограничение, подписи) |
| Правила | `CLAUDE.frontend.md`, «Гейты»: `test:unit` есть, область — чистые функции (без DOM и React-рендера); строка «тест-раннер не установлен» заменена |

Вне этапа: тесты компонентов (jsdom, Testing Library) — не ставим.

## 3. Что считаем выполненной работой

- `yarn test:unit` зелёный в контейнере node 24; намеренно сломанная функция
  делает тест красным (проверено и откатено).
- eslint, vite build зелёные; job `eslint-boundaries` в PR зелёный с шагом тестов.

## 4. Чек-лист приёмки

- [x] `vitest` в devDependencies, `yarn.lock` обновлён.
- [x] `npm run test:unit` запускает тесты `assets/**/*.test.ts`.
- [x] CI запускает тесты в PR, затрагивающих `site/assets/**`.
- [x] Тесты `amount.ts` и `slice.ts` покрывают ветки.
- [x] `CLAUDE.frontend.md` описывает новый гейт.

---

### Stage 11a: vitest — DONE

#### What was done
- `vitest` 3.2 в devDependencies; копия Vite 7, которую yarn поставил для
  vitest, схлопнута в `yarn.lock` на проектный Vite 6.4.1 (lock — только
  добавления).
- `vitest.config.ts` (node, алиас `@`, `assets/**/*.test.ts`), скрипт
  `test:unit`, шаг `yarn test:unit` в `frontend-lint.yml`.
- `amount.test.ts` (30) и `slice.test.ts` (10) рядом с модулями острова.
- `CLAUDE.frontend.md` «Гейты» и `docs/workflow/stage-report-frontend.md` —
  новый гейт и его область.

#### Definition of Done
- [x] `yarn test:unit` — 40 OK в node 24; подмена `all` → `any` в
      `sliceParams` даёт 1 красный тест (откатено).
- [x] eslint тестов, `yarn install --frozen-lockfile` чистый.

