# Этап 1. `ozon-fetcher` отдаёт последний снимок ресурса

## Задача

Канал «последний снимок» (решение Владельца 12.11, `../plan.md`): Symfony должна получить у сервиса
страницы последней загруженной ревизии `ozon.supplies` кабинета, не читая S3 сервиса.

## Результат

- Контракт `services/_contracts/service-snapshot/v1` (README + schema.json).
- `GET /internal/snapshots/latest?connection_ref=<ref>&resource_type=<rt>` в `fetchkit/httpapi`:
  токен `SERVICE_API_TOKEN`; ресурс — из белого списка сервиса (`ozon-fetcher`: `ozon.supplies`);
  ответ — `period_key` последней задачи с загруженной ревизией, `loaded_at` (начало ревизии), страницы
  JSON по порядку. Каждая страница читается `rawstore.Reader` со сверкой sha256.
- `store.Tasks.LatestContentPeriod` — последний `period_key` ресурса кабинета, у которого есть
  загруженная ревизия (`content_pages`), вне зависимости от текущего статуса задачи (перезагрузка
  сегодняшнего снимка не прячет вчерашний).

Ошибки: 401 без токена; 422 `invalid_request` — пустой/длинный `connection_ref` или ресурс вне списка;
404 `snapshot_not_found` — ревизии нет; 503 `snapshot_unavailable` — сбой БД/S3; 502 `snapshot_invalid`
— страница не JSON или повреждена (sha256).

## Что считаем выполненным

Тесты эндпоинта на фейках (все ветки ошибок, порядок страниц, проверка JSON), интеграционный тест
`LatestContentPeriod` (при `TEST_DB_DSN`); `go test -race`, `go vet`, `golangci-lint` по `shared/fetchkit`
и `ozon-fetcher` зелёные; README сервиса и контракт описывают эндпоинт.

## Чек-лист приёмки

- [x] 401 / 422 / 404 / 503 / 502 / 200 — тестами.
- [x] Ресурс вне белого списка не отдаётся даже при наличии ревизии.
- [x] Страницы — ровно ревизия `content_pages`, в её порядке.
- [x] Перезагрузка сегодняшнего снимка (`running`) — отдаётся последняя загруженная ревизия.
- [x] Гейты Go зелёные.

## Итог 12.11.2026

`go test -race` (`fetchkit/httpapi`, `fetchkit/store` на временном Postgres, `ozon-fetcher/internal/httpapi`),
`go vet`, `golangci-lint` (fetchkit, ozon-fetcher) — зелёные; `wb-fetcher` собирается (ядро общее,
`Snapshot` у него не задан — 503 `ingestion_disabled`).
