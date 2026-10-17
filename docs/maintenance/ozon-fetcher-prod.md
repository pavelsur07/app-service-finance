# ozon-fetcher на проде — включение

План — `docs/tasks/ozon-fetcher-ingestion/stages/stage-14.md`. Пока
`OZON_FETCHER_ENABLED` не равен `true`, деплой сервис не запускает и его БД
не трогает; `/internal/*` Symfony закрыт, пока не задан `INTERNAL_AUTH_TOKEN`.

## Что сделать один раз

1. **Timeweb S3:** бакет `vashfindir-services-raw` (если нет) и отдельный
   пользователь с доступом **только** к нему → Access Key и Secret Key.
2. **GitHub → Settings → Secrets and variables → Actions.**

   Secrets:

   | Имя | Значение |
   |---|---|
   | `INTERNAL_AUTH_TOKEN` | случайная строка ≥ 32 символов |
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
- Статус: `docker exec ozon-fetcher wget -qO- --header "Authorization: Bearer $INTERNAL_AUTH_TOKEN" http://127.0.0.1:8080/internal/fetch-status`
  — кабинет «Вумджой», задачи идут в `done`, пауз нет.
- Прод-синк Symfony: `app:marketplace:perf-report` — повторы `SyncOzonAccrualByDayMessage` не выросли.

## Все кабинеты

После 1–2 дней на «Вумджой» без ошибок — очистить
`OZON_FETCHER_CONNECTION_ALLOWLIST` и задеплоить.

## Откат

`OZON_FETCHER_ENABLED=false` и деплой — контейнер останавливается. Загрузка
Symfony от сервиса не зависит. Быстро без деплоя: `docker stop ozon-fetcher`.

## Ротация `INTERNAL_AUTH_TOKEN`

Сменить секрет и задеплоить: Symfony и сервис получают новое значение одним деплоем.
