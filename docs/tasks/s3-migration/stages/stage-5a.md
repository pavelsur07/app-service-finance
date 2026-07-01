## Stage 5a (PR 5a): Тип B — парсеры Marketplace/Catalog/Analytics — DONE

**Риск:** 🟡 MEDIUM (money-adjacent: парсеры сверок/себестоимости)
**Следующее действие:** continue autonomously (PR 5b), сначала PR 5a на ревью/мерж
**Ветка:** от чистого master

### Что сделано
5 парсер-сайтов переведены с `StorageService::getAbsolutePath()` + прямого парсинга
на `TemporaryLocalFile::with()` (скачивание во временную копию с расширением ключа):

| Сайт | Тип | Изменение |
|---|---|---|
| `Catalog\ImportProductsFromXlsAction` | read | `getAbsolutePath`+`parser->parse` → `with()` |
| `Marketplace\Reconciliation\OzonReportParserFacade` | read | `parseFromStoragePath` → `with()` (parseFromPath оставлен) |
| `Marketplace\ImportInventoryCostPriceHandler` | read | обёрнут вызов action (temp живёт весь импорт) |
| `MarketplaceAnalytics\DebugReparseMutualSettlementController` | read | `exists()`→404 + `with()` для парсинга |
| `Marketplace\LoadMutualSettlementAction` | **write+read** | `ensureDir`+`file_put_contents` → `write()`; `parse` → `with()` |

MarketplaceAds (`ExtractBatchesToRawDocumentsAction` + 2 storeBytes-сайта) — в PR 5b.
`DebugDownloadRawDocumentController` (тип A, download) — в PR 6.

### Расширение ключей
Все ключи содержат расширение (`.xlsx` и т.п.), `TemporaryLocalFile` (PR 3) отдаёт temp
с тем же расширением → парсеры (PhpSpreadsheet/XlsxReader) выбирают формат корректно.

### Затронутые файлы
- `src/Catalog/Application/ImportProductsFromXlsAction.php` — modified
- `src/Marketplace/Application/Reconciliation/OzonReportParserFacade.php` — modified
- `src/Marketplace/MessageHandler/ImportInventoryCostPriceHandler.php` — modified
- `src/MarketplaceAnalytics/Controller/Api/DebugReparseMutualSettlementController.php` — modified
- `src/Marketplace/Application/LoadMutualSettlementAction.php` — modified
- `tests/Unit/Marketplace/Application/Reconciliation/OzonReportParserFacadeStorageTest.php` — new

### Self-review
- [x] Scope compliance — только 5 парсеров (без MarketplaceAds/download)
- [x] Patterns / naming — `TemporaryLocalFile::with()` везде; `LoadMutualSettlement` write через `write()`
- [x] Forbidden actions — none
- [x] Security — companyId в путях/ключах сохранён; IDOR не затронут
- [x] Tests green — новый `OzonReportParserFacadeStorageTest` (storage→temp .xlsx→reader→cleanup, 6 assertions); регрессия unit Reconciliation/Catalog 14/14
- [x] DI — `lint:container` OK (5 конструкторов)
- [~] CS-Fixer — пред-существующий долг выравнивания (master даёт те же 5 of 5); новый тест чист; чужой стиль не переписывал
- [N/A] PHPStan — в проекте не установлен

### Команды для проверки
- `docker compose run --rm -T site-php-cli php bin/phpunit --filter OzonReportParserFacadeStorageTest`
- `docker compose run --rm -T site-php-cli php bin/console lint:container`

### Риски / на что обратить внимание ревьюеру
- `LoadMutualSettlementAction` — единственный write+read; проверить, что `write($relativePath, $binaryContent)` + последующий `with($relativePath, parse)` согласованы (ключ один и тот же). Под `local` файл физически там же.
- Money-adjacent: логика парсинга/агрегации не менялась, только источник файла (хранилище вместо диска).
- `ImportInventoryCostPriceHandler`: убран явный `file_exists`-чек; отсутствие файла теперь бросает `ObjectStorageException` из `readStream` → тот же catch → jobLog.fail + rethrow (эквивалентно).

### Открытые вопросы
- нет
