## Stage B3: sourceHash pre-check в UpsertFinancialTransactionAction — DONE

**Риск:** 🟡 MEDIUM
**Следующее действие:** continue autonomously (B4)

### Что сделано
- `UpsertFinancialTransactionAction::__invoke` — добавлена ветка NO_CHANGE: при найденной
  существующей транзакции, если `sourceDataHasher->hash($mapped->sourceData)` равен хешу
  `$transaction->getSourceData()` → возврат `null` до вызова `replaceFromNewerVersion`.
  `updated_at`/`externalUpdatedAt`/`occurredAt` в БД не движутся; событие для неизменившегося
  периода не порождается (закрывает P0.2 без новой колонки — допущение A3).
- `SourceDataHasher` внедрён в Action как параметр конструктора с дефолтом
  `= new SourceDataHasher()` (production-DI инжектит общий сервис; тесты не затронуты).
- Hash сравнивается on-the-fly в Action; Entity не получает деталей нормализации (по §2.1 спеки —
  чище, чем private-метод в Entity).

### Затронутые файлы
- `site/src/Ingestion/Application/Action/UpsertFinancialTransactionAction.php` — modified
- `site/tests/Integration/Ingestion/Application/UpsertFinancialTransactionActionTest.php` — modified
  (helper `mapped()` теперь выводит реалистичный `sourceData` из контента; +2 теста B3;
  listing-тест переведён на genuine content change)

### Self-review
- [x] Scope compliance — только B3
- [x] Patterns / naming — `final readonly class` Action сохранён
- [x] Forbidden actions — none (нет миграций; flush остаётся в вызывающем коде)
- [x] Security — companyId в сигнатуре `findByNaturalKey` сохранён; IDOR не затронут
- [x] CS-Fixer — green на изменённых файлах
- [x] Tests — integration green (5/5: 3 существующих + 2 новых B3)
- [x] PHPStan — N/A (не установлен)
- [x] ARCHITECTURE.md — N/A (внутренняя логика Action, контракт не меняется)

### Команды для проверки
- `docker compose run --rm -T site-php-cli php bin/phpunit -c phpunit.xml --testsuite integration --filter UpsertFinancialTransactionActionTest`

### Риски / на что обратить внимание ревьюеру
- Контракт B3: гейтом служит **только** `sourceData`. Если listingId/listingSku меняется при
  идентичном `sourceData`, upsert становится no-op — listing-rematch обрабатывается отдельным
  путём (`setListing`), не через upsert. Существующий listing-тест адаптирован под это (revision
  bump в sourceData), чтобы проверять propagation листинга на новой версии контента.
- Существующие интеграционные тесты использовали пустой `sourceData` при разной сумме —
  это было нереалистично; helper приведён к продакшн-семантике (sourceData отражает контент).

### Открытые вопросы
- нет
