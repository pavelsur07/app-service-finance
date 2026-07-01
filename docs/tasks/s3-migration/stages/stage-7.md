## Stage 7 (PR 7): Спрятать StorageService (@internal) — DONE

**Риск:** 🟢 LOW (документация + чистка тестов, рантайм не меняется)
**Следующее действие:** continue autonomously → остаётся только PR 8 (инфра-флип, 🔴 STOP)
**Ветка:** от чистого master

### Что сделано
- **`StorageService` помечен `@internal`** — низкоуровневый драйвер локального диска
  для `LocalObjectStorage`. Прикладной код обязан работать через `ObjectStorageInterface`
  (запись — `write()`, чтение для парсеров — `TemporaryLocalFile`). Прямое использование
  вне пакета Storage запрещено.
- **DI:** `StorageService` уже private (Symfony `_defaults`) — прод-код не может его
  инжектить/`->get()`. Публичность не менялась.
- **Причёсаны 3 прикладных integration-теста** (из ревью PR 5b, заметка A): setup файлов
  переведён со `StorageService` на `ObjectStorageInterface`:
  - `AdRawDocumentDownloadControllerTest`, `AdScheduledBatchDownloadControllerTest`,
    `ExtractBatchesControllerTest`: `get(StorageService)` → `get(ObjectStorageInterface)`;
    `storeBytes($body,$path)` → `write($path,$body)`; `$stored['storagePath']` → `$stored->path`,
    `$stored['fileHash']` → `hash('sha256',$body)` (или фикстур-хеш, где не проверяется);
    `@unlink(getAbsolutePath())` → `delete()`; `assertFileExists(getAbsolutePath())` →
    `assertTrue(exists())`.

### Осознанно НЕ трогали (легитимное driver-уровневое использование)
- `StorageServiceTest`, `StorageServiceIntegrationTest` — тестируют сам `StorageService`.
- `LocalObjectStorageTest` — мокает драйвер (тестирует делегацию local-обёртки).
- `ObjectStorageIntegrationTest` — проверяет, что local-драйвер пишет по пути `StorageService`.
- `ExtractBatchesToRawDocumentsActionTest` (unit) — конструирует `new StorageService($tmp)`
  внутри `LocalObjectStorage`+`TemporaryLocalFile` (сборка local-драйвера для unit-теста).
- `AdBatchPollerCommandTest` (integration) — мокает `StorageService` на уровне драйвера
  (перехват через делегацию `LocalObjectStorage`).
- `tests/bootstrap.php` — BypassFinals allowPaths для `StorageService` (нужен, т.к.
  `LocalObjectStorageTest` и `AdBatchPoller` мокают финальный класс).

### Затронутые файлы
- `src/Shared/Service/Storage/StorageService.php` — modified (@internal docblock)
- `tests/Integration/MarketplaceAds/Controller/AdRawDocumentDownloadControllerTest.php` — modified
- `tests/Integration/MarketplaceAds/Controller/AdScheduledBatchDownloadControllerTest.php` — modified
- `tests/Integration/MarketplaceAds/Controller/ExtractBatchesControllerTest.php` — modified

### Self-review
- [x] Scope compliance — @internal + чистка прикладных тестов; driver-уровневые не трогал
- [x] Forbidden actions — none
- [x] Tests green — converted 12/12; storage-layer + AdBatchPoller + ExtractBatches unit 52/52
- [x] DI — `lint:container` OK (StorageService private, не менялся)
- [~] CS-Fixer — 1 пред-существующее нарушение в `StorageService.php:94` (многострочный throw в `storeBytes`, не мой; докблок @internal чист)
- [N/A] PHPStan — в проекте не установлен

### Итог по коду
Весь прикладной код — на `ObjectStorageInterface`; `StorageService` спрятан за `@internal`
и private DI, остаётся только деталью local-драйвера. **Код-фаза миграции завершена.**
Остался PR 8 — инфра-флип на S3 (🔴 STOP, за S3-гейтом).

### Открытые вопросы
- нет
