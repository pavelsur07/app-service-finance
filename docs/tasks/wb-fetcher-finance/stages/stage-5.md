### Stage 5: прод — эталонный кабинет «Вумджой», затем все — IN PROGRESS

**Risk:** HIGH-EXTERNAL (прод-compose, деплой, секреты) · **Ветка:** `feat/wb-fetcher-finance` (одна на задачу, PR на handoff)
**Внешнее ревью:** Codex на handoff (вся ветка)

## 1. Какую задачу решаем

Включить wb-fetcher на проде так, чтобы не помешать текущей загрузке WB в Symfony
(общий лимит 1 запрос/мин на продавца) и иметь откат одним переключателем.

## 2. Решения и факты (02.11.2026)

- **Эталон — Вумджой ООО** (решение Владельца 02.11), компания
  `19621cff-b028-45d9-9193-11f47ad9a8b2`. Allowlist сервиса принимает `connection_ref`
  **или** `company_id` (`fetchkit/connections.WithAllowlist`) — id WB-подключения знать не нужно.
- Два переключателя, как у ozon-fetcher:
  - `vars.WB_FETCHER_ENABLED=true` — деплой создаёт БД/роль, мигрирует и поднимает контейнер;
    иначе останавливает его;
  - `vars.WB_FETCHER_CONNECTION_ALLOWLIST` — кабинеты сервиса; пусто — все.
  Symfony отдаёт оба ресурса всем WB-кабинетам (`wb_fetcher.finance_companies: ['*']`):
  снимок `provider=wildberries` читает только wb-fetcher, ограничение — в сервисе
  (один переключатель вместо двух списков).
- S3 — решение В1: тот же бакет и **те же ключи** `OZON_FETCHER_S3_*`, префикс `wb/`.
- Новые секреты: `WB_FETCHER_API_TOKEN` (Symfony → сервис), `WB_FETCHER_DB_PASSWORD` —
  hex-48; заводит Владелец (агенту `gh secret set` запрещён auto mode). `WB_FETCHER_SENTRY_DSN` —
  проект GlitchTip `wb-fetcher` заводит Владелец; пусто — ошибки только в логе.
- Переменные GitHub (`gh variable set`) агенту недоступны (auto mode) — ставит Владелец
  вместе с «merge and deploy».
- Через wrapper-ы `codex-*` логи и `/internal/*` сервиса не видны; статус после деплоя —
  витрина покрытия (вкладка Wildberries / `dbal:run-sql` по `service_coverage_*`) и
  `codex-docker-ps`.

## 3. Что будет результатом работы

| Что | Где |
|---|---|
| Сервис `wb-fetcher` (профиль `wb-fetcher`, без ingress, сеть default, healthcheck `/health`, 256M), env по образцу ozon-fetcher, S3 — ключи `OZON_FETCHER_S3_*` | `docker-compose.prod.yml` |
| scheduler: `WB_FETCHER_BASE_URL=http://wb-fetcher:8080`, `WB_FETCHER_API_TOKEN` | `docker-compose.prod.yml` |
| БД `wb_fetcher` и роль `svc_wb_fetcher`, идемпотентно | `docker/wb-fetcher/db-bootstrap.sql` |
| Образ `wb-fetcher` в матрице сборки; экспорт секретов/переменных; проверка алфавита; шаг `[4c/5]` (bootstrap → pull → `-migrate < /dev/null` → `up --wait`), выключено — `stop` | `.github/workflows/deploy.yml` |
| Гейт деплой-скрипта: wb-fetcher run/exec со своим stdin (существующий тест покрывает), профиль — проверка | `tests/shell/deploy-workflow-test.sh` (прогон) |
| `wb_fetcher.finance_companies: ['*']` | `site/config/services.yaml` |
| Инструкция: включение, проверка, откат, сверка, все кабинеты | `docs/maintenance/wb-fetcher-prod.md` |

## 4. Проверки (для прод-этапа «до» снимается первым)

До включения (02.11.2026, `app:marketplace:perf-report --from=2026-10-27 --to=2026-11-02`, read-only):
- [x] `SyncWbFinancialReportDayMessage`: 447 сообщений, failed 0, retried 0
- [x] очередь `async_wb_finance`: 447, redelivered 0; этап `api_fetch` wb — 447 вызовов, errors 0

До handoff (локально):
- [x] `docker compose -f docker-compose.prod.yml config` валиден с профилем `wb-fetcher` и без
- [x] `tests/shell/deploy-workflow-test.sh` зелёный (10 команд run/exec со своим stdin)
- [x] образ `wb-fetcher` собирается из `services/` (как в матрице), `-h` показывает `-migrate`
- [x] functional Symfony: снимок `provider=wildberries` отдаёт оба ресурса (`MarketplaceConnectionsControllerTest` 7/7)

После деплоя (Владелец ставит переменные; проверка — агент):
- [ ] деплой дошёл до «Деплой завершён»; `wb-fetcher` `healthy` (`codex-docker-ps`)
- [ ] через ≤ 10 минут витрина `wb-fetcher` заполнена: `cabinets_total = 1`, дни и недели Вумджой
- [ ] после ночи: `perf-report` — retried/failed `SyncWbFinancialReportDayMessage` не выше «до» (0)
- [ ] 3 ночи без ERROR, прошлая неделя `done` в понедельник/вторник
- [ ] сверка эталона за 14 дней (число дней `done` сервиса против `sales_report success` Symfony)
- [ ] все кабинеты: Владелец очищает `WB_FETCHER_CONNECTION_ALLOWLIST` и деплоит; первая ночь чистая

## 5. Чек-лист приёмки

- [ ] секреты `WB_FETCHER_API_TOKEN`, `WB_FETCHER_DB_PASSWORD` — **Владелец**: `gh secret set` агенту запрещён классификатором auto mode (Secret-Store Writes, 02.11)
- [x] compose, deploy.yml, bootstrap БД; выключенный сервис не стартует и не трогает БД (ветка `else` — `stop`)
- [x] `docs/maintenance/wb-fetcher-prod.md`; `ARCHITECTURE.md` (параметр и Changelog 2.120)
- [ ] откат: `WB_FETCHER_ENABLED` ≠ `true` → деплой останавливает контейнер; Symfony-путь не затронут
