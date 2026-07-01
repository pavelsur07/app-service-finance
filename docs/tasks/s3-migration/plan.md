# S3 Migration — Plan (Phase 0)

> Цель: увести файловое хранилище с локального docker-тома на S3 (timeweb),
> чтобы приложение стало stateless и могло разъехаться на несколько машин.
> Способ: **branch-by-abstraction** — сначала весь код на единый
> `ObjectStorageInterface` под драйвером `local` (поведение не меняется),
> и только в самом конце — флип на S3.

## Контекст / факты (аудит выполнен)

- Инфраструктура уже написана: `league/flysystem` + `flysystem-aws-s3-v3`,
  `ObjectStorageInterface`, реализации `LocalObjectStorage` / `FlysystemS3ObjectStorage`,
  `ObjectStorageFactory`. Драйвер по умолчанию — `local` (S3 подключён, но выключен).
- Хранилище **глобальное, один драйвер, один бакет** (env `APP_OBJECT_STORAGE_DRIVER`).
  Не пер-компанийное. Суффикс `_default` в services.yaml — это Symfony env-fallback.
- Env-префикс: `APP_OBJECT_STORAGE_*`
  (`DRIVER`, `S3_BUCKET`, `S3_REGION`, `S3_ENDPOINT`, `S3_ACCESS_KEY`,
  `S3_SECRET_KEY`, `S3_PATH_STYLE_ENDPOINT`).
- Объём данных на дисках — **крошечный**: `site_company_storage` 6 файлов / 32 КБ,
  `site_storage` 8 файлов / 72 МБ. Тяжёлые `raw_documents` (600+ МБ) лежат в БД (bytea),
  на S3 не едут.
- Всё уже под корнем `var/storage/` → при S3-sync ключи ложатся 1:1, физического
  перемещения на этапе рефактора нет.

## Ключевой принцип (behavior-preserving рефактор)

> **Ключ, передаваемый в `ObjectStorageInterface`, обязан точно равняться
> относительному пути, уже сохранённому в БД.**

- Корень `LocalObjectStorage` = `var/storage` (текущий `app.storage_root`).
- Пути в БД не переписываем, файлы не двигаем, миграций БД нет.
- Единая схема ключей — только для **новых** записей, старые исторические
  пути остаются как есть (для S3 ключ есть ключ).

## Текущее состояние абстракции (проблема)

`ObjectStorageInterface` = правильная точка входа, но **~18 мест инжектят
`StorageService` напрямую** в обход интерфейса и намертво привязаны к
локальному диску (`getAbsolutePath()` + raw `file_put_contents`/`fopen`).
Плюс 2 потока (тип C) пишут на диск вообще мимо `StorageService`.

Цель порядка: `ObjectStorageInterface` — единственный публичный сервис,
`StorageService` — приватная деталь `LocalObjectStorage`.

## Классификация точек ввода-вывода

| Тип | Что | Где | Как чинится |
|---|---|---|---|
| **A** | Отдача файла в HTTP-ответ | `AdScheduledBatchDownloadController`, `AdRawDocumentDownloadController`, `Api/Admin/DownloadBronzeController`, `Api/DebugDownloadRawDocumentController` | `getAbsolutePath()`+`BinaryFileResponse` → `readStream()`+`StreamedResponse` |
| **B** | Путь скармливается парсеру (нужен реальный файл) | `ImportProductsFromXlsAction` (PhpSpreadsheet), `LoadMutualSettlementAction`, `Reconciliation/OzonReportParserFacade`, `ImportInventoryCostPriceHandler`, `ExtractBatchesToRawDocumentsAction` (ZipArchive), `Api/DebugReparseMutualSettlementController` | Хелпер `TemporaryLocalFile::with()` — скачать в `/tmp`, отдать парсеру, удалить |
| **C** | Прямая запись на диск в обход всего (cross-tier: web пишет, worker читает по пути) | `Cash/Controller/Import/CashFileImportController` + `Cash/Service/Import/File/*` (`var/storage/cash-file-imports`), `Telegram/Controller/TelegramWebhookController` (`var/storage/telegram-imports`) | web пишет через `write()`, worker читает через `TemporaryLocalFile` |

**Не трогаем:** `tempnam(sys_get_temp_dir())` в Ozon-клиентах (это `/tmp`, эфемерно),
`php://memory` / `php://output` (потоки, не диск), `MakeModuleCommand` (dev-кодоген).

## Проектные решения (best practices, утверждены)

- **Интерфейс:** добавить `readStream()` и `delete()`. `withLocalCopy` в интерфейс
  НЕ добавлять — отдельный сервис `TemporaryLocalFile`, зависит от интерфейса.
- **Схема ключей (новые записи):** `{module}/{companyId}/{ulid}.{ext}`.
  Полный ключ хранить в БД. Дату-партицию (`/{yyyy}/{mm}/`) добавлять только там,
  где будет lifecycle-истечение (raw-импорты).
- **Провайдер:** прод — один бакет timeweb. dev/test — драйвер `local` (без креденшелов).
  MinIO в compose — опционально, только для отладки S3-пути.
- **Шифрование:** сейчас — бакет приватный + TLS-only. SSE-S3 — целевой механизм,
  включается позже флагом бакета (без кода). App-level шифрование не дублировать.
- **Бэкап/retention:** versioning бакета (= бэкап) + одно lifecycle-правило
  (удалять неактуальные версии через 30 дней). Отдельный пайплайн не строим.
- **Cutover без fallback-декоратора** (данных 72 МБ — оверинжиниринг не нужен):
  пауза загрузок → `s3 sync` (копия) → флип env → сверка → грейс 2 недели → удалить local.
- **Грейс/рубильник:** local-том остаётся как холодный бэкап; авто-сверка паритета
  (пути из БД ↔ существование в S3); удаление local — решение Владельца после
  зелёной сверки + 2 недель без ошибок «файл не найден» в GlitchTip.

## Итерации (PR-per-stage)

Каждый PR: драйвер `local`, поведение байт-в-байт, свои тесты, откатывается
независимо. Бэкенд меняет только PR 8.

### PR 1 — расширить контракт `ObjectStorageInterface` — 🟡 MEDIUM
- `readStream(string $path)` + `delete(string $path)` в интерфейс.
- Реализовать в `LocalObjectStorage` и `FlysystemS3ObjectStorage`.
- Новый сервис `TemporaryLocalFile` (read → tempnam → callable → unlink).
- Additive: вызовов ещё нет, рантайм не меняется.
- Тесты: unit на `TemporaryLocalFile` (создаёт/удаляет tmp, пробрасывает исключение),
  unit на `Local.readStream/delete`.

### PR 2 — upload-контроллеры (write side) → интерфейс — 🟡 MEDIUM ✅ DONE
- 5 сайтов с чистой записью загрузки: `Catalog\ProductImportController` (web + Api),
  `Marketplace\ReconciliationUploadController`, `Marketplace\Inventory\InventoryImportController`,
  `Marketplace\ReconcileCostsAction`.
- `storeUploadedFile()` → `write($path, $file->getContent())`; путь сохраняем как есть;
  `originalFilename` из `$file->getClientOriginalName()`; `fileHash` считаем на месте
  (`hash('sha256', …)`, как в `StoreRawBatchAction`).
- **Scope-корректировка:** 2 storeBytes-сайта MarketplaceAds
  (`DownloadOzonAdReportHandler`, `AdBatchPollerCommand`) вынесены в PR 5 — они плотно
  связаны с `ExtractBatchesToRawDocumentsAction` (читает `getAbsolutePath` сразу после
  записи) и их тесты мокают `StorageService` для обеих сторон; узел нельзя разрывать.
- Тесты: functional `ProductImportUploadTest` — файл сохраняется и читается по пути из БД.

### PR 3 — тип C: cash-импорт → интерфейс — 🟡 MEDIUM
- `CashFileImportController` пишет через `write()`, ключ = прежний относительный путь.
- `CashFileImportService`/`Reader` читают через `TemporaryLocalFile`.
- **Cross-tier блокер снят** (web ↔ worker больше не через общий диск).
- Тесты: регрессионный smoke реального импорта (загрузка → парсинг воркером).

### PR 4 — тип C: telegram-импорт → интерфейс — 🟡 MEDIUM
- `TelegramWebhookController` → `write()`; читатель → `TemporaryLocalFile`.
- Тесты: приём файла из webhook → обработка воркером.

### PR 5 — тип B: парсеры через `TemporaryLocalFile` (+ узел MarketplaceAds) — 🟡 MEDIUM
- `LoadMutualSettlementAction`, `OzonReportParserFacade`, `ImportInventoryCostPriceHandler`,
  `ExtractBatchesToRawDocumentsAction`, `DebugReparseMutualSettlementController`.
- **Плюс из PR 2:** `DownloadOzonAdReportHandler`, `AdBatchPollerCommand` (storeBytes-запись)
  — мигрируются вместе с `ExtractBatchesToRawDocumentsAction`, т.к. store→getAbsolutePath
  handoff и общие тесты, мокающие `StorageService`. Обновить эти тесты один раз здесь.
- Можно разбить на 2 PR: `Marketplace` / `MarketplaceAds`.
- Тесты: по одному happy-path на каждый парсер; обновить unit+integration тесты
  `DownloadOzonAdReportHandler` / `AdBatchPollerCommand` под `ObjectStorageInterface`.

### PR 6 — тип A: download-контроллеры → `readStream()` — 🟢 LOW
- 4 контроллера: `getAbsolutePath()`+`BinaryFileResponse` → `readStream()`+`StreamedResponse`.
- Проксирование через приложение сохраняем (авторизация/companyId в контроллере,
  бакет приватный). Presigned URL не вводим.
- Тесты: functional на скачивание (200 + корректные байты + проверка companyId).

### PR 7 — спрятать `StorageService` — 🟢 LOW
- Когда прямых внешних вызовов не осталось: `@internal`, убрать из public DI,
  оставить видимым только для `LocalObjectStorage`.
- Тесты: `make stan` (нет внешних импортов `StorageService`).

### PR 8 — инфра + флип на S3 — 🔴 HIGH (STOP перед выполнением)
1. Бакет timeweb (приватный, TLS-only, versioning вкл., lifecycle 30 дней на
   неактуальные версии; SSE-S3 подготовлен, но выключен).
2. `APP_OBJECT_STORAGE_*` в host-env + прокинуть в `x-php-env` и в блок env
   `scheduler` в `docker-compose.prod.yml` (сейчас переменных там нет).
3. Пауза загрузок/воркеров (окно секунды) → `aws s3 sync var/storage → s3://bucket` (копия).
4. Флип `APP_OBJECT_STORAGE_DRIVER=s3` → старт.
5. Авто-сверка паритета (пути из БД ↔ `exists` в S3) → грейс 2 недели → удалить local.
- Rollback: вернуть `APP_OBJECT_STORAGE_DRIVER=local` (данные на месте).

## Открытые вопросы к Владельцу (закрыть до PR 8)

1. Значения для timeweb: endpoint, region, path-style (0/1), имя бакета.
2. Точное окно паузы для cutover (согласовать время).
3. Кто владелец команды сверки паритета и решения об удалении local.

## Риски

- Тип C (PR 3/4) меняет поток загрузки — обязателен ручной smoke реального импорта.
- Тип B — парсеры чувствительны к реальному пути; проверить, что `TemporaryLocalFile`
  корректно чистит `/tmp` при исключении (нет утечки файлов).
- PR 8 — единственная точка невозврата; local-том держим как откат весь грейс-период.

## Follow-ups (вне scope)

- Разбор, что именно лежит в `site_storage` vs `site_company_storage` (перенесли всё,
  разбираемся потом — объём мал).
- Возможный вынос `raw_documents` из БД (bytea) в S3 — отдельная задача.
- Включение SSE-S3, если потребует комплаенс.
