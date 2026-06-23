## Stage B5: IngestionFacade отдаёт FinancialTransactionView DTO — DONE

**Риск:** 🔴 HIGH (внутренний контракт Facade) — 🛑 STOP перед merge, нужно ревью Владельца
**Следующее действие:** 🛑 STOP перед merge B5; реализация B6 продолжается на ветке.

### Что сделано
- Новый `App\Ingestion\Application\DTO\FinancialTransactionView` (`final readonly class`) — поля по
  §4.4 спеки; enum-поля (`source`/`type`/`direction`) экспонируются как scalar `value` (строки).
  `enrichmentStatus` НЕ добавлен (TASK-FIX-06).
- `IngestionFacade::getTransactions` → `iterable<FinancialTransactionView>` через generator-проектор
  поверх `FinancialTransactionRepository::iterateByPeriod`; добавлен private `projectTransactionToView`.
  Память не растёт на больших периодах (генератор).
- Единственный потребитель `App\Finance\Application\Action\RebuildPnlPeriodAction` переведён на DTO:
  убран `instanceof`/импорт `App\Ingestion\Entity\FinancialTransaction`; `type`/`direction`
  конвертируются обратно в enum (`TransactionType::from`/`TransactionDirection::from`) для
  `PnlCategoryResolver`.
- Ad-hoc арх-тест `tests/Unit/Ingestion/Architecture/EntityBoundaryTest` (без новых зависимостей):
  запрещает `App\Ingestion\Entity\*` вне `App\Ingestion\*` (исключение Variant-B `PLDirtyPeriod`);
  отдельная строгая проверка, что `FinancialTransaction` не утекает вообще.
- `ARCHITECTURE.md`: обновлён контракт `getTransactions` (DTO), добавлено правило границы + ссылка
  на арх-тест.

### Затронутые файлы
- `site/src/Ingestion/Application/DTO/FinancialTransactionView.php` — new
- `site/src/Ingestion/Facade/IngestionFacade.php` — modified
- `site/src/Finance/Application/Action/RebuildPnlPeriodAction.php` — modified
- `site/tests/Unit/Ingestion/Architecture/EntityBoundaryTest.php` — new
- `site/tests/Integration/Ingestion/Application/NormalizeRawRecordActionTest.php` — modified (consumer под DTO)
- `ARCHITECTURE.md` — modified

### Self-review
- [x] Scope compliance — только B5
- [x] Patterns / naming — `final readonly class` DTO; `final readonly class` Facade
- [x] Forbidden actions — none; миграций нет; messenger.yaml не тронут
- [x] Security — `companyId` сохранён в DTO (tenant-aware downstream)
- [x] CS-Fixer — green на изменённых файлах
- [x] Tests — unit suite green (1126, +2 archtest); integration green
      (RebuildPnlPeriodActionTest + RebuildDirtyPnlPeriodsCommandTest + NormalizeRawRecordActionTest 10/10)
- [x] `lint:container --env=test` — green
- [x] PHPStan — N/A (не установлен)
- [x] ARCHITECTURE.md — обновлён (DTO + контракт + правило границы)

### Команды для проверки
- `docker compose run --rm -T site-php-cli php bin/phpunit --testsuite unit --filter EntityBoundaryTest`
- `docker compose run --rm -T site-php-cli php bin/phpunit -c phpunit.xml --testsuite integration --filter 'RebuildPnlPeriodActionTest|NormalizeRawRecordActionTest'`
- `docker compose run --rm -T site-php-cli php bin/console lint:container --env=test`

### Риски / на что обратить внимание ревьюеру
- 🔴 Меняется внутренний контракт `IngestionFacade::getTransactions` (Entity → DTO). Единственный
  потребитель — `RebuildPnlPeriodAction` — обновлён в этом же этапе (координированный merge, O3).
- DTO хранит enum как строки (по §4.4). Потребитель делает `Enum::from(...)`. При желании можно
  богатить DTO enum-объектами — отдельная задача.

### Открытые вопросы
- нет
