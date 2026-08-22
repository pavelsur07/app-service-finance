# План: график «Динамика остатка на счетах» в legacy `/finance`

Task branch: `task/ui-dashboard-balance-dynamics`

Baseline:
- Stage base: `2520183b41244058644642461aa93ffeffb92737`.
- После установки зависимостей без изменения lock-файлов `CompanyEntityTest`:
  8 tests, 12 assertions — green.
- `npm run build`: green; сохранено существующее предупреждение о
  `@symfony/ux-turbo/package.json`.

## Stage 1: Минимальный остаток компании

Risk: HIGH-LOCAL
owner_gate: no
release_candidate: no
independently_deployable: no
stage_base_commit: `2520183b41244058644642461aa93ffeffb92737`

Definition of Done:
- `Company` хранит неотрицательный `minimumBalance` как shared `Money`, default `0 RUB`.
- Существующие компании безопасно backfill-ятся; миграция обратима на local/test DB.
- Сумма и валюта редактируются в существующей форме компании.
- Account-level `minimumSafeBalance` не меняется.

Work items:
- 1.1 — Добавить embedded Money и миграцию.
- 1.2 — Добавить form mapping для immutable Money и подключить к CompanyType/Twig.
- 1.3 — Покрыть entity/form mapping тестами и проверить migration up/down/up.
- 1.4 — Stage checks, internal review, external REVIEW_GREEN, Stage Report, commit/push/Draft PR.

Stage checks:
- Targeted unit/functional tests; migration up/down/up; schema validation.
- PHP CS и Twig lint.

Reviewer focus:
- Money без float, safe backfill, form mapping, совместимость существующих Company writers.

## Stage 2: API и финансовые временные ряды

Risk: HIGH-LOCAL
owner_gate: no
release_candidate: no
independently_deployable: no
stage_base_commit: commit Stage 1

Definition of Done:
- `GET /api/finance/dashboard/balance-dynamics` принимает DTO `period=30|60|90`, `currency=FiatCurrency`.
- Response DTO отдаёт inclusive period, company threshold и aligned daily points.
- Balance — active-account closing total выбранной валюты; flow — signed split net по видам деятельности.
- Tenant isolation, transfer/technical/deleted/unallocated exclusions и decimal arithmetic покрыты тестами.

Work items:
- 2.1 — Request/response DTO рядом с invokable controller.
- 2.2 — DBAL read queries для balance/flow series без N+1.
- 2.3 — Application provider: даты, нули, порог, сериализация decimal strings.
- 2.4 — API controller и behavioral tests.
- 2.5 — Stage checks, reviews, Stage Report, commit/push/PR update.

Stage checks:
- Unit/integration/functional tests для period/currency, formulas, tenant isolation и endpoint errors.
- PHP CS, container/schema validation.

Reviewer focus:
- Знаки, split double-counting, company scope, dates, N+1, stable JSON contract.

## Stage 3: React-виджет legacy `/finance`

Risk: MEDIUM
owner_gate: yes
release_candidate: yes
independently_deployable: yes
stage_base_commit: commit Stage 2

Definition of Done:
- Виджет под четырьмя legacy KPI, только на `/finance`; новый UI и `/dashboard` не меняются.
- Native responsive SVG показывает balance, threshold, breaches, optional operating/financing/investing flows.
- Периоды 30/60/90 (default 30), tooltip, loading/error/retry/empty и accessible states работают.
- Новых frontend-зависимостей нет; CSS scoped и переиспользуемый.

Work items:
- 3.1 — Отдельный Vite entry и legacy Twig mount.
- 3.2 — Typed API client/hook через existing shared HTTP utilities.
- 3.3 — Smart widget и presentational SVG chart.
- 3.4 — Scoped reusable chart CSS и responsive/accessibility polish.
- 3.5 — Full checks, complete task reviews, handoff, commit/push/Draft PR update.

Stage checks:
- Frontend lint/build/UI-kit mapping checks; Twig lint; functional `/finance` test.
- Manual responsive smoke where environment permits.
- Full task internal review and external REVIEW_GREEN.

Reviewer focus:
- Legacy-only isolation, React states, SVG edge cases, accessibility, no dependency/bundle regression.

## Release и Production Gates

- Final Release Gate: Draft PR с `baseRefName=master`, известный CI status, owner decision required.
- Ready/merge/automatic production deploy не выполнять без явного разрешения Владельца.
- Production migration/acceptance — отдельный Production Gate.

## Зафиксированные решения

- Порог один на компанию; применяется только при совпадении валюты.
- Настройка порога — в карточке компании, не внутри графика.
- Период графика независим от фильтра legacy KPI.
- Нет FX-конвертации, cache, chart dependency и изменения account-level threshold.
