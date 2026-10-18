# Handoff: ozon-fetcher-coverage-admin — страница «Покрытие Сервис GO»

**Ветка:** `feat/ozon-fetcher-coverage-admin` · **База:** `master` `9fbc46fd8`
**План:** `plan.md`, этапы `stages/stage-1.md` … `stage-4.md`

## Что сделано

| Этап | Результат | Коммиты |
|---|---|---|
| 1 | Go: `GET /internal/coverage` по контракту `services/_contracts/service-coverage/v1`; `SERVICE_API_TOKEN` для `/internal/*` сервиса; 503 до первого снимка кабинетов | `536b8541c`, `7a9490a93` |
| 2 | Symfony-модуль `ServiceGateway`: клиент (таймауты, токен, `X-Trace-Id`, строгий разбор контракта), витрина `service_coverage_snapshot` / `service_coverage_row`, Action с заменой в транзакции, cron `app:service-gateway:coverage:refresh` каждые 10 минут | `b13aee8fe`, `7a9490a93`, `8400af282` |
| 3 | Страница `/admin/services/ozon-fetcher/coverage` (ROLE_ADMIN): тепловая карта месяца M / M-1 / M-2, пункт меню в Ingestion | `3bea43a91` |
| 4 | Прод: секрет `OZON_FETCHER_API_TOKEN` (заведён), env `ozon-fetcher` и `scheduler`, проверки в `deploy.yml` | `3e4a84efa` |

## Миграция

`Version20261018100000` — две новые пустые таблицы. Обратима (`down()` удаляет), существующие
данные не трогает, бэкап не нужен. По `docs/workflow/release.md` — отдельный
`production_action=migrations` до деплоя.

## Порядок выкатки

Этап 1 закрывает `/internal/*` сервиса для старого токена, поэтому всё — одним деплоем:
merge → `production_action=migrations` → деплой. Секрет уже заведён, поэтому сервис получит
`SERVICE_API_TOKEN`, а scheduler — адрес и токен в одном деплое.

## Проверки

- Go: `go vet`, `golangci-lint` (0 issues), `go test -race ./...` — все пакеты ok.
- Symfony: PHPStan целиком (baseline не менялся) — 0; CS без кеша — 0 из 3094; `strict_types` — 0;
  PHPUnit целиком — 6666 тестов OK.
- Внешнее ревью Codex этапов 1–2: 3 итерации, 4 IMPORTANT исправлены, итог `REVIEW_GREEN`.
- Внешнее ревью Codex этапов 3–4: 2 IMPORTANT (процент покрытия без пропусков; кавычка в секрете
  исполнялась бы до проверки) — исправлены, повторная итерация — см. PR.
- Стенд: сервис + мок Ozon → команда → витрина 83 строки, построчно совпадает с `/internal/coverage`;
  сбой сервиса и неверный токен — витрина прежняя, токена в логах нет; скриншоты 1280 / 390.

## Приёмка на проде (после деплоя)

1. `fetch-status` и `coverage` отвечают по `SERVICE_API_TOKEN` внутри контейнера `ozon-fetcher`.
2. Через ≤ 10 минут витрина наполнена: 4 кабинета, M, M-1, M-2; данные совпадают с `/internal/coverage`.
3. Страница открывается админу.
4. GlitchTip (оба проекта) — без новых ошибок.

## Вне задачи, замечено

- Все секреты деплоя подставляются в текст удалённого скрипта; для секретов сервисов добавлена проверка
  на раннере до SSH, остальные (`APP_SECRET`, `DATABASE_URL`, …) — отдельной задачей.

- Хлебные крошки админки везде «Панель управления» (TODO в `admin/partials/_header.html.twig`).
- На 390 px сайдбар админки не сворачивается — общее для админки.
