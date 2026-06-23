## Stage B7: Idempotency через ловлю unique violation — DONE

**Риск:** 🟡 MEDIUM (с STOP-консультацией Владельца — спека-механизм оказался неприменим)
**Следующее действие:** continue autonomously (B8)

### Контекст и решение Владельца
Спека B7 предписывала ловить `UniqueConstraintViolationException` **внутри**
`UpsertFinancialTransactionAction`. Это архитектурно неприменимо: Action только `persist`'ит,
а `flush` батчевый — в outer DBAL-транзакции `NormalizeRawRecordAction`. Эмпирический пробник
подтвердил: unique violation на flush **закрывает EntityManager и отравляет outer-транзакцию**
даже при `use_savepoints: true`. Inline-recovery потребовал бы reset ManagerRegistry (как
`StoreRawBatchAction`), что бросает весь батч нормализации.

**Решение Владельца: «Clean recoverable retry».** Ловим на flush в `NormalizeRawRecordAction`,
откатываем outer-tx, логируем INFO, бросаем `RecoverableMessageHandlingException` → Messenger
ретраит. На ретрае строки уже существуют → upsert'ы становятся no-change (B3) → конвергенция
без дублей. Необработанных исключений не остаётся.

### Что сделано
- `NormalizeRawRecordAction` — добавлен `catch (UniqueConstraintViolationException)` перед
  generic `catch (\Throwable)`: rollback outer-tx (если активна) + INFO-лог
  (`companyId`/`rawRecordId`) + `throw RecoverableMessageHandlingException(previous: $exception)`.

### Затронутые файлы
- `site/src/Ingestion/Application/Action/NormalizeRawRecordAction.php` — modified
- `site/tests/Integration/Ingestion/Application/NormalizeRawRecordActionTest.php` — modified (+1 тест)

### Self-review
- [x] Scope compliance — только B7 (catch translation)
- [x] Patterns / naming — без изменений структуры
- [x] Forbidden actions — none; messenger.yaml не тронут (retry-strategy существующая)
- [x] Security — companyId логируется (не PII/секрет)
- [x] CS-Fixer — green на изменённых файлах
- [x] Tests — integration green (NormalizeRawRecordActionTest 6/6)
- [x] PHPStan — N/A (не установлен)
- [x] ARCHITECTURE.md — N/A

### Команды для проверки
- `docker compose run --rm -T site-php-cli php bin/phpunit -c phpunit.xml --testsuite integration --filter 'NormalizeRawRecordActionTest::testConcurrentInsertViolationIsTranslatedToRecoverableRetry'`

### Заметки по тесту (детерминизм без флака)
- Кросс-коннекшн симуляция гонки **деадлочила** (убита по таймауту, exit 143) — отброшена.
- Финальный тест детерминирован на одном соединении: pre-`persist` (без flush) строки с тем же
  natural key → `findByNaturalKey` (DB-query) её не видит → батч-flush вставляет обе → unique
  violation на нужном flush. Без второго соединения, без хука, без деадлока. Проверяет точный тип
  исключения и трансляцию в `RecoverableMessageHandlingException`, плюс отсутствие утечки строк
  после rollback.

### Риски / на что обратить внимание ревьюеру
- Полный `IdempotentHandlerTrait` с `processed_messages` сознательно отложен (A2). Текущая защита —
  конвергенция через retry + B3. Issues-дедуп (часть B7 спеки) не делался: в Phase 0 нет
  уникального индекса на issues (перенесено).

### Открытые вопросы
- нет
