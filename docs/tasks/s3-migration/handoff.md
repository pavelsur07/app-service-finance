# S3 Migration — Handoff (задача завершена)

Файловое хранилище переведено с локального docker-тома на объектное хранилище
timeweb S3. Cutover в проде выполнен и проверен.

## Итог по этапам (все в master)

| PR | Что | Тип |
|---|---|---|
| #2081 | `ObjectStorageInterface` + `readStream`/`delete` + `TemporaryLocalFile` | контракт |
| #2084 | upload-контроллеры → `write()` | тип C (write) |
| #2083 | cash-импорт через хранилище — снят межмашинный блокер web↔worker | тип C |
| #2085 | telegram-импорт (write-only) | тип C |
| #2086 | парсеры Marketplace/Catalog через `TemporaryLocalFile` | тип B |
| #2087 | парсеры MarketplaceAds + storeBytes-сайты | тип B |
| #2088 | download-контроллеры → `readStream`/`StreamedResponse` | тип A |
| #2089 | `StorageService` → `@internal` (спрятан за local-драйвер) | чистка |
| #2090 | wiring env timeweb S3 в прод (DRIVER=local) | инфра |
| #2091 | документация переменных в `site/.env` | docs |
| #2092 | **флип `APP_OBJECT_STORAGE_DRIVER: local → s3`** | cutover |

## Ключевые решения

- **Branch-by-abstraction:** весь код на `ObjectStorageInterface` под `local`, флип — одной строкой в конце.
- **Инвариант ключа:** ключ хранилища = относительный путь из БД → миграций схемы не было, старые записи резолвятся 1:1.
- **`TemporaryLocalFile`** — для парсеров, которым нужен реальный файл (скачивание во временную копию с сохранением расширения, гарантированная чистка).
- **hash на месте вызова** (не в `StoredObject`) — по конвенции `StoreRawBatchAction`.
- **Cutover без fallback-декоратора** (данных ~72 МБ) — sync (копия) → флип → грейс.

## Прод-конфигурация (`docker-compose.prod.yml`)

- `APP_OBJECT_STORAGE_DRIVER: s3` (в `x-php-env` и `scheduler`)
- endpoint `https://s3.twcstorage.ru`, bucket `ccd24eb8-1c82-4de4-bd69-9706a7b5443b`,
  region `ru-1`, path-style `0`
- Ключи — GitHub Secrets `APP_OBJECT_STORAGE_S3_ACCESS_KEY` / `_SECRET_KEY`

## Проверка cutover (выполнена)

- ✅ `aws s3 sync --dryrun` — пусто (паритет полный; ~20 109 объектов / 71.77 МБ, размер совпал).
- ✅ Cash-импорт: mapping показал колонки → `write()` + `readStream()` в S3 работают.
- ✅ GlitchTip — без `ObjectStorageException` / «файл не найден».

## Осталось (грейс-период)

- [ ] **2 недели** мониторинга GlitchTip на storage-ошибки (локальные тома **не трогать** — холодный бэкап).
- [ ] После грейса + при отсутствии инцидентов — **удалить локальные тома** `current_site_storage`,
      `current_site_company_storage` (решение Владельца).

## Откат (доступен до удаления local)

Вернуть `APP_OBJECT_STORAGE_DRIVER: s3 → local` (2 строки) + деплой. Локальные данные целы
(sync был копией). Файлы, загруженные после флипа, — только в S3; при откате домигрировать
`aws s3 sync s3://... /data/storage`, если критично.

## Follow-ups (сознательно вне scope)

- Content-addressed cash/telegram-ключи (без `companyId`) — при желании привести к схеме
  `{module}/{companyId}/...` отдельной задачей (не регрессия, поведение сохранено).
- Возможный вынос `raw_documents` из БД (bytea) в S3 — отдельная задача.
- Включение SSE-S3, если потребует комплаенс (бакет уже приватный + TLS).
- Пред-существующий баг `BotLinkService` (TypeError, падает на master) — не связан с миграцией.
