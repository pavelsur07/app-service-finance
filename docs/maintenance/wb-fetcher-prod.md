# wb-fetcher на проде — включение

План — `docs/tasks/wb-fetcher-finance/stages/stage-5.md`. Сервис грузит детальный отчёт
о реализации WB (день и неделя) в raw S3 (`vashfindir-services-raw`, префикс `wb/`).
Загрузку WB в Symfony он не заменяет и от неё не зависит. Пока `WB_FETCHER_ENABLED` не
равен `true`, деплой сервис не запускает и его БД не трогает.

## Что сделать один раз

1. **Секреты GitHub** (Settings → Secrets and variables → Actions → Secrets; только
   символы `A-Za-z0-9._~-` — деплой проверяет):

   | Имя | Значение |
   |---|---|
   | `WB_FETCHER_API_TOKEN` | случайная строка ≥ 32 символов — токен «Symfony → сервис» (`SERVICE_API_TOKEN` сервиса) |
   | `WB_FETCHER_DB_PASSWORD` | случайный пароль роли `svc_wb_fetcher` |
   | `WB_FETCHER_SENTRY_DSN` | DSN проекта GlitchTip `wb-fetcher`; не задан — ошибки только в логе контейнера |

   S3 — **те же ключи**, что у ozon-fetcher (`OZON_FETCHER_S3_*`): один бакет и один
   пользователь, сервисы разделены префиксом ключа. `INTERNAL_AUTH_TOKEN` — общий.

2. **Переменные** (Variables):

   | Имя | Значение |
   |---|---|
   | `WB_FETCHER_CONNECTION_ALLOWLIST` | не задана с 03.11.2026 — все кабинеты WB; принимает `company_id` или id подключения через запятую (эталон при включении — «Вумджой ООО», `19621cff-b028-45d9-9193-11f47ad9a8b2`) |
   | `WB_FETCHER_ENABLED` | `true` |

3. **Деплой** (merge в master или `production_action=deploy`). Шаг `[4b2/5]` создаёт
   роль `svc_wb_fetcher` и БД `wb_fetcher` (`docker/wb-fetcher/db-bootstrap.sql`),
   прогоняет миграции и поднимает контейнер `wb-fetcher`. Scheduler получает
   `WB_FETCHER_BASE_URL`/`WB_FETCHER_API_TOKEN` и раз в 10 минут обновляет витрину покрытия.

## Что делает сервис

- Кабинеты — `GET /internal/marketplace-connections?provider=wildberries` (Symfony отдаёт оба
  ресурса всем WB-кабинетам; кабинеты ограничивает allowlist сервиса).
- `wb.finance.sales-report.daily` — вчера с 04:00 МСК, хвост 14 дней; дни перечитываются раз в сутки.
- `wb.finance.sales-report.weekly` — прошлая неделя с понедельника 04:00, три недели
  перечитываются раз в сутки; пустой ответ до ср 00:00 МСК — «отчёт не готов», повтор через час.
  Дневное окно кабинета продлевается до понедельника самой старой перечитываемой недели (сверка).
- Лимит WB — 1 запрос/мин на продавца, общий с Symfony: сервис ходит не чаще раза в 70 с на
  кабинет и только со слотом лимитера Symfony (`POST /internal/wb-finance/sales-reports/slot`,
  контракт `wb-finance-quota` v1). Symfony недоступна — запросов к WB нет, повтор через минуту.
  429 — пауза из заголовков, темп снижается, cooldown передаётся в Symfony.

## Проверка после деплоя

- `codex-docker-ps` — `wb-fetcher` `healthy`.
- Витрина (через ≤ 10 минут): админка → «Покрытие Сервис GO» → вкладка **Wildberries**:
  «Кабинетов в загрузке» = 1, дни идут в `1/1`, «Данные на» свежие. Read-only SQL:
  `docker exec scheduler php /app/bin/console dbal:run-sql "SELECT resource_type, count(*), sum(done) FROM service_coverage_row WHERE service = 'wb-fetcher' GROUP BY 1"`.
- Статус сервиса (Владелец, `vf-prod`):
  `docker exec wb-fetcher sh -c 'wget -qO- --header "Authorization: Bearer $SERVICE_API_TOKEN" http://127.0.0.1:8080/internal/fetch-status'`.
- Symfony не пострадала: `codex-console app:marketplace:perf-report --from=… --to=…` —
  `SyncWbFinancialReportDayMessage` retried/failed не выше замера «до» (27.10–02.11: 447, 0/0).

## Все кабинеты

С 03.11.2026 сервис на всех кабинетах: `WB_FETCHER_CONNECTION_ALLOWLIST` удалена (решение Владельца,
без трёх ночей на эталоне), `WB_FETCHER_ENABLED=true`. Сузить обратно — задать allowlist и задеплоить
(`production_action=deploy`).

## Откат

`WB_FETCHER_ENABLED` ≠ `true` и деплой — контейнер останавливается, БД и raw остаются.
Быстро без деплоя: `docker stop wb-fetcher`. Загрузка WB в Symfony не затронута; вкладка
Wildberries показывает прежние данные и «устарело» через 30 минут.

## Ротация токенов

`WB_FETCHER_API_TOKEN` и `INTERNAL_AUTH_TOKEN` меняются независимо: сменить секрет и
задеплоить — scheduler и сервис получают новое значение одним деплоем.
