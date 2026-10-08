### Stage 1: файловый импорт завершается (`done`) и не обрезается на 200 строках — DONE

**Risk:** HIGH-LOCAL
**Stage base commit:** `0bb5fad1`
**Work items:** 1.1, 1.2, 1.3, 1.4

#### What was done
- 1.1 — Регрессионные тесты на полный путь сообщение → `CashFileImportHandler` →
  статус задачи и журнала. Общий фикстурный класс `CashFileImportTestCase`:
  компания, счёт, системный проект и ЦФО, CSV в `ObjectStorageInterface`,
  job через `CashFileImportFacade`.
- 1.2 — `CashFileImportService::flushBatch()` отсоединяет только созданные в
  пачке `CashTransaction` и закэшированных `Counterparty` (`detach()`) вместо
  `clear(X::class)`. В ORM 3 `clear(X::class)` очищал весь UnitOfWork.
  Состояние пачки сбрасывается в начале каждого импорта: сервис живёт в
  воркере между сообщениями.
- 1.3 — `ImportLogger::finish()` и `CashFileImportFacade::commitJob()`:
  `flush($x)` → `flush()`. Поведение то же (ORM 3 игнорирует аргумент),
  код больше не обещает точечный flush.
- 1.4 — `ApplyAutoRulesForTransactionHandler`, `EnqueueAutoRulesForRangeHandler`:
  `clear(CashTransaction::class)` → `clear()`. Поведение идентично, ложный
  аргумент убран.

#### Red on old code (`0bb5fad1` + новые тесты)
`docker compose run --rm -T -e XDEBUG_MODE=off site-php-cli php bin/phpunit tests/Integration/Cash/MessageHandler/Import/CashFileImportHandlerFlowTest.php tests/Integration/Cash/Service/Import/File/CashFileImportBatchBoundaryTest.php`
→ `EEEEEE` (6/6 errors). Во всех случаях исключение вылетает из обработчика:

```
Doctrine\ORM\ORMInvalidArgumentException: A new entity was found through the relationship
'App\Cash\Entity\Import\ImportLog#company' that was not configured to cascade persist …
/app/src/Cash/MessageHandler/Import/CashFileImportHandler.php:85
```

Для файлов больше 200 строк к нему добавляются `CashTransaction#moneyAccount`
и `CashTransaction#projectDirection`.

Механизм совпадает с продом:
1. Финальный `flushBatch()` отсоединяет всё.
2. `ImportLogger::finish()` делает `persist()` отсоединённого журнала.
3. Обработчик в ветке `fail()` вызывает `flush` и падает на той же
   невалидной UnitOfWork.
4. Сообщение уходит в retry, retry выходит на `STATUS_QUEUED !== status`,
   задача остаётся в `processing`.

Совпадает и сообщение единственной `failed`-задачи на проде.

#### Files changed
- `site/src/Cash/Service/Import/File/CashFileImportService.php` — modified
- `site/src/Cash/Service/Import/ImportLogger.php` — modified
- `site/src/Cash/Service/Import/File/CashFileImportFacade.php` — modified
- `site/src/Cash/MessageHandler/ApplyAutoRulesForTransactionHandler.php` — modified
- `site/src/Cash/MessageHandler/EnqueueAutoRulesForRangeHandler.php` — modified
- `site/tests/Integration/Cash/Service/Import/File/CashFileImportTestCase.php` — new
- `site/tests/Integration/Cash/Service/Import/File/CashFileImportBatchBoundaryTest.php` — new
- `site/tests/Integration/Cash/MessageHandler/Import/CashFileImportHandlerFlowTest.php` — new

#### Definition of Done
- [x] Импорт 1 строки через handler → `done`, `ImportLog.finishedAt` проставлен, `createdCount = 1`.
- [x] 201 и 401 строка → ровно 201 и 401 операция, журнал закрыт, дневной
  приход за дату равен сумме строк (остатки пересчитаны по всем пачкам).
- [x] Повторный импорт 201 строки → 0 создано, 201 `skippedDuplicates`, задача `done`.
- [x] Повторный импорт, где все строки дубли (3 строки) → `done`.
- [x] Контрагент из строк 1 и 250 → один `Counterparty`, обе операции на нём.
- [x] `ImportLogger::finish()` не полагается на `flush($log)`.
- [x] Исключено и не тронуто: `batchSize`, дедупликация, автоправила по существу.

#### Checks
- baseline: целевой набор Cash — OK (40 tests, 153 assertions).
- targeted: новые тесты — red 6/6 на старом коде, green 6/6 (36 assertions) после фикса.
- module: `php bin/phpunit tests/Unit/Cash tests/Integration/Cash` — OK (389 tests, 1547 assertions).
- PHPStan по 8 изменённым файлам (`var/phpstan-local.neon`: 2 процесса,
  `reportUnmatchedIgnoredErrors: false`) — No errors. Первый прогон дал 3
  ошибки типов в новых тестах, исправлены.
- php-cs-fixer dry-run по 8 файлам — 0 to fix.

#### Internal review
- iterations: 1; BLOCKER/IMPORTANT: none.
- Проверено:
  - `CashTransactionAutoRulesSubscriber` ставит в очередь по id, удержания
    ссылок нет;
  - импорт не создаёт `CashTransactionSplit`; cascade persist у splits без
    detach — безвредно;
  - контрагент пачки N находится в пачке N+1 через БД (тест);
  - замена `clear(X)` → `clear()` в автоправилах идентична по поведению;
    тесты автоправил зелёные.
- FOLLOW-UP: мёртвые `CashTransactionPersister.php_` и
  `CashFileImportReader.php_` с тем же `clear(X::class)` (P3 аудита).

#### External review
- required: на handoff (Large); после Stage — нет (diff < 500 строк).

#### Risks / reviewer focus
- Частичный импорт при падении на середине по-прежнему частичный (вне scope, решение Владельца).
- Обработчик всё ещё не умеет записать `failed` при закрытом EM — это Stage 2.

#### Next
- continue to Stage 2 automatically.
