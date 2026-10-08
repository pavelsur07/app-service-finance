### Stage 2: повторный номер документа не валит импорт, падение доводит задачу до `failed` — DONE

**Risk:** HIGH-LOCAL
**Stage base commit:** `915eeb36`
**Work items:** 2.1, 2.2, 2.3, 2.4, 2.5

#### What was done
- 2.1 — Поиск потребителей `external_id` у `import_source='file'`.
  - Бизнес-потребителей нет.
  - `CashFacade` и `CashTransactionService` работают с `importSource`, который
    передаёт вызывающий (`telegram`).
  - Диагностика `ReportCashflowOpsCheckController` использует `external_id`
    только для флага дублей. Для пустого поля у неё есть запасная эвристика
    (дата + счёт + сумма + направление), так что флаг для новых файловых строк
    продолжает работать.
- 2.2 — Тесты в `CashFileImportHandlerFlowTest` (интеграционные, полный путь
  через handler):
  - повтор номера в одном файле;
  - номер уже занят легаси-строкой с `external_id`;
  - ошибка PostgreSQL посреди импорта — сумма за пределами `numeric(18,2)`.
  В `CashFileImportHandlerTest` добавлен юнит на ветку закрытого EM и на
  содержимое лога.
- 2.3 — `CashFileImportService`:
  - номер документа больше не пишется в `external_id`, он хранится в
    `doc_number`, дубли ловит `dedupeHash`;
  - `ImportLogger::finish()` в `finally` вызывается только на открытом EM и
    больше не подменяет исходное исключение.
- 2.4 — `CashFileImportHandler`:
  - при закрытом EM — `resetManager()` по образцу
    `ProcessRawDocumentStepMessageHandler`, затем свежий job, `fail()` и
    закрытие журнала;
  - один `logger->error` с `jobId`, `companyId`, классом и местом исключения,
    без текста (у DBAL в нём SQL с данными выписки);
  - `info` о старте и финише.

#### Red on old code (`915eeb36` + новые тесты)
`php bin/phpunit tests/Integration/Cash/MessageHandler/Import/CashFileImportHandlerFlowTest.php`
→ `..EEE`. Все три новых теста падают так:

```
Doctrine\ORM\Exception\EntityManagerClosed: The EntityManager is closed.
/app/src/Cash/MessageHandler/Import/CashFileImportHandler.php:85
```

Исключение вылетает из handler. В проде это retry, а retry пропускает задачу
в `processing`. Юнит на ветку закрытого EM на старом коде не собирается
(нет `ManagerRegistry` в конструкторе), поэтому доказательство красного — эти
интеграционные тесты.

#### Files changed
- `site/src/Cash/Service/Import/File/CashFileImportService.php` — modified
- `site/src/Cash/MessageHandler/Import/CashFileImportHandler.php` — modified
- `site/tests/Integration/Cash/MessageHandler/Import/CashFileImportHandlerFlowTest.php` — modified
- `site/tests/Unit/Cash/MessageHandler/Import/CashFileImportHandlerTest.php` — modified

#### Definition of Done
- [x] Два документа с одним номером → обе операции, `done`, номер в `doc_number`.
- [x] Номер, занятый легаси-строкой с `external_id`, → операция создана, `done`.
- [x] Исключение импорта при закрытом EM → `failed`, читаемый `errorMessage`
  (контракт и `testFailedImportStoresReadableErrorWithoutDebugMarkers`
  сохранены), `ImportLog.finishedAt` проставлен, handler не бросает.
- [x] Один `logger->error` с `jobId`/`companyId`, без текста исключения.
- [x] Исключено: all-or-nothing, `dedupeHash`, маршрутизация Messenger.

#### Checks
- targeted: red 3/3 → green 5/5 (`CashFileImportHandlerFlowTest`) + 3/3 unit.
- module: `php bin/phpunit tests/Unit/Cash tests/Integration/Cash` — OK (393 tests, 1567 assertions).
- PHPStan по 4 изменённым файлам — No errors.
- php-cs-fixer — 1 правка пробела в тесте применена, затем 0.

#### Internal review
- iterations: 1; BLOCKER/IMPORTANT: none.
- FOLLOW-UP:
  - `errorMessage` задачи по-прежнему содержит текст DBAL-исключения с
    данными строки и показывается в UI (существующий контракт, P1-2 аудита);
  - если упадёт и flush на свежем EM (БД недоступна целиком), задача
    останется в `processing`.

#### External review
- required: на handoff.

#### Next
- continue to Stage 3 automatically.
