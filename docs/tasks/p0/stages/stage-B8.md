## Stage B8: RunSyncChunkHandler retry semantics — DONE

**Риск:** 🟡 MEDIUM
**Следующее действие:** continue autonomously (B5 — 🔴 HIGH, STOP перед merge)

### Что сделано
- `RunSyncChunkHandler` — catch-all `\Throwable` больше **не** вызывает `markJobFailed`.
  Теперь: WARNING-лог (`companyId`/`jobId`/`exceptionClass`/`errorMessage`) + rethrow → Messenger
  применяет retry-strategy. Раньше любая транзиентная ошибка → мгновенный `FAILED`, а ранний выход
  `if (status->isTerminal()) return;` делал ретраи no-op'ом (P0.7).
- `ConnectorAuthException` (немедленный `markJobFailed('auth')` + Unrecoverable) и
  `ConnectorRateLimited`/`ConnectorTransient` — без изменений.
- Удалён мёртвый private `failureReason()` (использовался только в старом catch-all).
- `SyncJobFailureSubscriber` помечает `FAILED` после исчерпания ретраев — без изменений.

### Затронутые файлы
- `site/src/Ingestion/MessageHandler/RunSyncChunkHandler.php` — modified
- `site/tests/Integration/Ingestion/MessageHandler/RunSyncChunkHandlerTest.php` — modified (+1 тест)
- `site/tests/Integration/Ingestion/Fixtures/FakeConnector.php` — modified (one-shot `failNextPullWith`)

### Self-review
- [x] Scope compliance — только B8
- [x] Patterns / naming — без изменений
- [x] Forbidden actions — none; `messenger.yaml` НЕ тронут (retry-strategy существующая)
- [x] Security — companyId/jobId логируются (не PII/секрет)
- [x] CS-Fixer — green на изменённых файлах
- [x] Tests — integration green: RunSyncChunkHandlerTest + SyncJobFailureSubscriberTest 9/9.
      Новый тест: транзиентный `\RuntimeException` на 1-й попытке → job RUNNING (не FAILED),
      rethrow; ретрай → job COMPLETED.
- [x] PHPStan — N/A (не установлен)
- [x] ARCHITECTURE.md — N/A

### Команды для проверки
- `docker compose run --rm -T site-php-cli php bin/phpunit -c phpunit.xml --testsuite integration --filter 'RunSyncChunkHandlerTest|SyncJobFailureSubscriberTest'`

### Риски / на что обратить внимание ревьюеру
- Зависит от того, что для `RunSyncChunkMessage` в `messenger.yaml` настроена retry-strategy
  (не меняем). Если ретраев 0 — на первой же ошибке `SyncJobFailureSubscriber` пометит FAILED
  (поведение для пользователя то же, но без промежуточных ретраев). Конфиг транспорта не трогался.

### Открытые вопросы
- нет
