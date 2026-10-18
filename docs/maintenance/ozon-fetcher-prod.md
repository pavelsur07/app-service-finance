# ozon-fetcher на проде — включение

План — `docs/tasks/ozon-fetcher-ingestion/stages/stage-14.md`. Пока
`OZON_FETCHER_ENABLED` не равен `true`, деплой сервис не запускает и его БД
не трогает; `/internal/*` Symfony закрыт, пока не задан `INTERNAL_AUTH_TOKEN`.

## Что сделать один раз

1. **Timeweb S3:** бакет `vashfindir-services-raw` (если нет) и отдельный
   пользователь с доступом **только** к нему → Access Key и Secret Key.
2. **GitHub → Settings → Secrets and variables → Actions.**

   Secrets (только символы `A-Za-z0-9._~-` — деплой проверяет и падает на других):

   | Имя | Значение |
   |---|---|
   | `INTERNAL_AUTH_TOKEN` | случайная строка ≥ 32 символов — токен «сервис → Symfony» |
   | `OZON_FETCHER_API_TOKEN` | случайная строка ≥ 32 символов — токен «Symfony → сервис» (`SERVICE_API_TOKEN` сервиса, `/internal/*` сервиса); не совпадает с `INTERNAL_AUTH_TOKEN` |
   | `OZON_FETCHER_DB_PASSWORD` | случайный пароль |
   | `OZON_FETCHER_S3_ACCESS_KEY_ID` | Access Key из п. 1 |
   | `OZON_FETCHER_S3_SECRET_ACCESS_KEY` | Secret Key из п. 1 |

   Variables:

   | Имя | Значение |
   |---|---|
   | `OZON_FETCHER_CONNECTION_ALLOWLIST` | `90b72323-784a-4268-88aa-10778c3da78b` (ООО «Вумджой», seller); пусто — все кабинеты |
   | `OZON_FETCHER_ENABLED` | `true` |

3. **Деплой** (обычный merge в master или `production_action=deploy`). Шаг
   деплоя сам создаёт роль `svc_ozon_fetcher` и БД `ozon_fetcher`
   (`docker/ozon-fetcher/db-bootstrap.sql`), прогоняет миграции сервиса и
   запускает контейнер `ozon-fetcher`.

## Проверка после деплоя

- `docker ps` — `ozon-fetcher` `healthy`.
- Статус: `docker exec ozon-fetcher sh -c 'wget -qO- --header "Authorization: Bearer $SERVICE_API_TOKEN" http://127.0.0.1:8080/internal/fetch-status'`
  — кабинет «Вумджой», задачи идут в `done`, пауз нет.
- Прод-синк Symfony: `app:marketplace:perf-report` — повторы `SyncOzonAccrualByDayMessage` не выросли.

## Витрина покрытия и страница «Покрытие Сервис GO»

- Scheduler раз в 10 минут: `app:service-gateway:coverage:refresh` → `GET http://ozon-fetcher:8080/internal/coverage`
  с `OZON_FETCHER_API_TOKEN` → таблицы `service_coverage_snapshot` / `service_coverage_row` (БД Symfony).
- Страница `/admin/services/ozon-fetcher/coverage` (ROLE_ADMIN) читает только витрину; при остановленном
  сервисе показывает прежние данные и «устарело» через 30 минут.
- Сервис недоступен → WARNING, exit 0, витрина прежняя; нарушение контракта → ERROR, exit 1 (GlitchTip).
- Ручной прогон: `docker exec scheduler php /app/bin/console app:service-gateway:coverage:refresh`.

## Все кабинеты

После 1–2 дней на «Вумджой» без ошибок — очистить
`OZON_FETCHER_CONNECTION_ALLOWLIST` и задеплоить.

## Откат

`OZON_FETCHER_ENABLED=false` и деплой — контейнер останавливается. Загрузка
Symfony от сервиса не зависит. Быстро без деплоя: `docker stop ozon-fetcher`.

- Покрытие: то же с `/internal/coverage` (контракт `services/_contracts/service-coverage/v1`).

## Ротация токенов

`INTERNAL_AUTH_TOKEN` и `OZON_FETCHER_API_TOKEN` меняются независимо: сменить
секрет и задеплоить — Symfony и сервис получают новое значение одним деплоем.
