# Этап 0 — удаление старого `src/Billing/`

## Задача

Убрать неиспользуемый модуль `src/Billing/` (Plan / Feature / PlanFeature /
Integration / SubscriptionIntegration / UsageCounter / Subscription,
`AccessManager`) и его 7 таблиц, чтобы этап 1 строил новый модуль с чистого
места, без двух моделей подписки рядом.

Замер прода 01.11.2026 (read-only, `codex-psql-ro`): во всех таблицах
`billing_plan`, `billing_feature`, `billing_plan_feature`,
`billing_integration`, `billing_subscription`,
`billing_subscription_integration`, `billing_usage_counter` — **0 строк**.
Бэкап не нужен.

## Результат

Коммит в ветке задачи `feat/billing-core`:

1. Удалены `site/src/Billing/` целиком (включая `README.md`, `Fixture/`) и
   `site/tests/Unit/Billing/`.
2. Снят mapping `Billing` в `site/config/packages/doctrine.yaml`.
3. Из `site/phpstan-baseline.neon` удалены записи по удалённым файлам
   (baseline только сокращается).
4. Миграция `DROP TABLE IF EXISTS` для 7 таблиц в порядке зависимостей;
   `down()` воссоздаёт пустые таблицы с индексами и FK (DDL из
   `Version20260310…20260317`) — миграция обратима.
5. `ARCHITECTURE.md`: строка `Billing` в таблице модулей — «удалён 01.11.2026,
   новый модуль — этап 1 `docs/tasks/billing`»; Changelog.

Остаётся без изменений (переиспользует этап 2/1): заглушка `/admin/billing`
(не зависит от модуля), запись `'App\\Billing\\' => Module::ADMIN` в
`ModuleAccessMap` (пространство имён вернётся на этапе 1).

## Что считаем выполненным

- В репозитории нет ссылок на `App\Billing\` кроме `ModuleAccessMap`.
- `doctrine:schema:validate` на тестовой БД после миграций — в синхроне.
- Миграция проходит `up` → `down` → `up` на локальной БД.
- Гейты зелёные, baseline уменьшился на число удалённых записей.
- После деплоя на проде таблиц `billing_*` нет.

## Чек-лист приёмки

- [x] `grep -rn 'App\\Billing' site/src site/config site/tests` — только `ModuleAccessMap`
- [x] `git grep -n 'src/Billing' site/phpstan-baseline.neon` — пусто
- [x] миграция `up` / `down` / `up` локально без ошибок
- [x] `doctrine:schema:validate` — OK (mapping и schema)
- [x] `make site-stan` — без новых ошибок, baseline −N
- [x] `make site-cs-check`, `make site-cs-strict-types` — зелёные
- [x] `make site-test-unit` — зелёный; функциональные тесты админки (`/admin/billing`) и ModuleAccessMap — зелёные
- [x] `ARCHITECTURE.md` обновлён
- [x] после деплоя: `SELECT count(*) FROM information_schema.tables WHERE table_name LIKE 'billing\_%'` = 0

## Итог

01.11.2026, первый коммит ветки `feat/billing-core`.

- Удалено 36 файлов (`src/Billing/`, `tests/Unit/Billing/`), mapping снят.
- Baseline PHPStan: −28 записей (168 строк), добавленных нет.
- Миграция `Version20261101200000`: на локальной БД (своя пара pg+redis)
  `up` → 0 таблиц `billing_*`, `down` → 7, `up` → 0.
- `doctrine:schema:validate`: mapping OK; расхождение схемы с mapping есть и
  до этапа (sequence `companies_public_id_seq`, `balance_categories`,
  `deals_deal_sequence` и др.), строк про `billing` в нём 0 — к задаче не
  относится.
- PHPStan — No errors; CS и strict_types по изменённым файлам — чисто;
  unit — 4282 OK; Functional/Admin, Company/Security, Architecture — 58 OK.
- На проде 01.11.2026 (PR #2853): миграция применена в 20:02 МСК, таблиц
  старого модуля 0.
