## Stage 2 (PR 2): Upload-контроллеры (write side) → ObjectStorageInterface — DONE

**Риск:** 🟡 MEDIUM
**Следующее действие:** continue autonomously (PR 3), сначала PR 2 на ревью/мерж Владельцем
**Ветка:** стек поверх PR 1 (`feat/s3-migration-pr1-storage-contract`)

### Что сделано
- 5 сайтов чистой записи загрузки переведены со `StorageService` на `ObjectStorageInterface`:
  - `Catalog\Controller\ProductImportController` (web)
  - `Catalog\Controller\Api\ProductImportController`
  - `Marketplace\Controller\Api\ReconciliationUploadController`
  - `Marketplace\Controller\Inventory\InventoryImportController`
  - `Marketplace\Application\ReconcileCostsAction`
- `storeUploadedFile()` → `write($path, $file->getContent())`. **Пути в БД не меняются.**
- `originalFilename` берётся из `$file->getClientOriginalName()` (на месте вызова),
  `fileHash` (нужен в `ReconcileCostsAction`) считается `hash('sha256', $contents)` —
  по конвенции существующего потребителя интерфейса `StoreRawBatchAction`.
- Драйвер остаётся `local`, поведение прода не меняется.

### Scope-корректировка (важно ревьюеру)
Изначально план относил к PR 2 ещё 2 storeBytes-сайта MarketplaceAds
(`DownloadOzonAdReportHandler`, `AdBatchPollerCommand`). При self-review выяснилось,
что они плотно связаны с `ExtractBatchesToRawDocumentsAction` (читает `getAbsolutePath`
сразу после записи — это PR 5) и их unit+integration тесты мокают `StorageService`
для обеих сторон. Разрывать узел между PR = мокать обе стороны и удвоить возню.
→ Перенесены в PR 5 (план обновлён). PR 2 = 5 чистых upload-сайтов без тестовой ряби.

### Затронутые файлы
- `src/Catalog/Controller/ProductImportController.php` — modified
- `src/Catalog/Controller/Api/ProductImportController.php` — modified
- `src/Marketplace/Controller/Api/ReconciliationUploadController.php` — modified
- `src/Marketplace/Controller/Inventory/InventoryImportController.php` — modified
- `src/Marketplace/Application/ReconcileCostsAction.php` — modified
- `tests/Functional/Catalog/ProductImportUploadTest.php` — new

### Self-review
- [x] Scope compliance — только write-сайты; парсеры/скачивание не тронуты (пути идентичны → работают под local)
- [x] Patterns / naming — hash на месте вызова как в `StoreRawBatchAction`
- [x] Forbidden actions — none
- [x] Security — companyId в путях сохранён; `write()`→`storeBytes` добавляет проверку path-traversal (строже, чем `storeUploadedFile`); IDOR не затронут
- [x] Tests green — functional `ProductImportUploadTest` (6 assertions) + PR1 unit 7/20 не регрессировали
- [x] DI — `lint:container` OK (менял 5 инъекций)
- [~] CS-Fixer — на этих файлах пред-существующий долг (Yoda, unused imports, выравнивание): master даёт те же «6 of 7». Мои правки CS-нейтральны; чужой стиль не переписывал (scope-дисциплина)
- [N/A] PHPStan — в проекте не установлен
- [N/A] ARCHITECTURE.md — нового Facade/Enum/Entity нет

### Команды для проверки
- `docker compose run --rm -T site-php-cli php bin/phpunit --testsuite functional --filter ProductImportUploadTest`
- `docker compose run --rm -T site-php-cli php bin/console lint:container`

### Риски / на что обратить внимание ревьюеру
- `write()` грузит содержимое загрузки в память (`$file->getContent()`) вместо `move()`.
  Для xls/xlsx-импортов (мелкие) приемлемо; крупных загрузок среди этих 5 сайтов нет.
- `ReconciliationUploadController` / `ReconcileCostsAction` пишут через интерфейс, а парсинг
  (`parseFromStoragePath` / reconciliationAction) пока читает `getAbsolutePath` (PR 5).
  Под драйвером `local` путь один и тот же — работает; после флипа на S3 это чинит PR 5.

### Открытые вопросы
- нет
