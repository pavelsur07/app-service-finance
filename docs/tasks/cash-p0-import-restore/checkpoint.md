## Current checkpoint

**Phase:** Stage 2 — done; next Stage 3 / Work item 3.1
**Status:** implementing
**Stage base commit:** Stage 1: `0bb5fad1`; Stage 2: `915eeb36`; Stage 3: — (коммит Stage 2)

### Completed
- plan.md составлен 2026-10-08 по аудиту модуля Cash (P0-1…P0-3).
- Phase 0 (2026-10-08): baseline зелёный, факты прода сняты (ниже), план
  уточнён по ним.
- Stage 1 DONE: фикс `flushBatch()` (detach вместо clear), `flush()` без
  аргумента, red 6/6 → green 6/6; отчёт `stages/stage-1.md`.
- Stage 2 DONE: номер документа не в external_id; handler доводит до failed
  при закрытом EM, error-лог без текста; red 3/3 → green; `stages/stage-2.md`.
- Draft PR #2590.

### Checks and baseline
- `docker compose run --rm -T -e XDEBUG_MODE=off site-php-cli php bin/phpunit tests/Unit/Cash/Service/Import/File tests/Unit/Cash/MessageHandler/Import tests/Integration/Cash/Service/Import/File tests/Integration/Cash/Command/SoftDeleteCompanyTransactionsCommandTest.php tests/Integration/Cash/MessageHandler`
  — OK (40 tests, 153 assertions), pre-existing failures нет.

### Production facts (before), 2026-10-08, read-only через codex-psql-ro

`cash_file_import_jobs` по статусам: done 9, failed 3, processing 66, queued 15.

Processing (66 задач, 3 компании, 47 разных файлов, все с `import_log.finished_at IS NULL`):

| created_count | задач | компаний | период | строк записано |
|---|---|---|---|---|
| 0 | 6 | 2 | 2026-02-08…2026-08-07 | 0 |
| 1–199 | 37 | 3 | 2026-02-08…2026-10-06 | 1622 |
| =200 | 23 | 1 | 2026-08-03…2026-08-08 | 4600 |

- Импортов с `created_count > 200` за всё время: **0**.
- `done` только у задач с `created_count = 0` (все строки — дубли или
  ошибки). Единственный `done` с созданными строками — 2026-01-22, до
  рефакторинга `CashFileImportService` от 2026-02-08. Вывод: **с февраля
  каждый импорт, создавший хотя бы одну строку, зависает в `processing`.**
- Строки записаны: по компаниям `sum(import_log.created_count)` =
  `count(cash_transaction WHERE import_source='file')` (5585/5585, 635/635, 9/9).
- Дневные остатки по 12 затронутым счетам сходятся с операциями (11 из 12
  без расхождений; у счёта `10d8d659…` 1 день без строки остатка,
  70 893,24 — к задаче не относится, FOLLOW-UP). Значит, пересчёт остатков
  отрабатывает, падение — после него.
- `failed`: 1 × `ORMInvalidArgumentException: A new entity was found through
  the relationship 'App\Cash\Entity\Transaction\CashTransaction…'` (P0-2),
  2 × `Import file not found for hash`.
- В GlitchTip падений импорта нет (обработчик глотает исключение). Issue 414
  (2026-10-06, `uniq_cts_tx_category`, `CashTransactionAutoRuleController`) —
  отдельный дефект, FOLLOW-UP. `messenger_messages` пуста.

Объяснение по коду: финальный `flushBatch()` после записи тоже делает полный
`clear()`. Затем `ImportLogger::finish()` делает `persist()` отсоединённого
`ImportLog`: ORM 3 считает его NEW, а `flush($log)` аргумент игнорирует. Дальше
INSERT с существующим PK, EM закрыт. Обработчик падает на `flush` закрытого EM,
retry выходит на `STATUS_QUEUED !== status`. Подтвердить тестом в WI 1.1.

P0-1 (повтор номера документа): **в данных не наблюдался**. `external_id`
заполнен только у 7 строк файлового импорта (компания `5d26f9aa…`, февраль).
Дефект кодовый и латентный.

P0-3 (`--restore`): массовое удаление применялось к одной компании
(`fbebb285…`): mass_deleted 3257, manual_deleted 0, active 5385. Сейчас restore
вреда не причинил бы; дефект латентный, станет реальным при первом ручном
удалении в этой компании до отката.

Для FOLLOW-UP §3.3 после деплоя:
- 37 + 6 задач `processing` с полностью записанными строками → корректный
  статус `done`, `import_log.finished_at` проставить (SQL write, отдельное
  одобрение);
- 23 задачи с `created_count = 200` — **частичные импорты** (в файле было
  больше 200 строк). Пользователям нужно перезагрузить эти файлы после фикса:
  дедупликация пропустит первые 200 строк и догрузит остальные. Статус
  задач — `failed` с понятным сообщением;
- 15 задач `queued` с 2026-02-07/08 — устаревшие, к фиксу не относятся.

### Review status
- internal: iteration 0, open: none
- external: round 0, result: pending

### Exact next action
- Stage 3, WI 3.1: красные тесты restore в `SoftDeleteCompanyTransactionsCommandTest`.

### Files to inspect first on resume
- docs/tasks/cash-p0-import-restore/plan.md
- site/src/Cash/Service/Import/File/CashFileImportService.php
- site/src/Cash/Service/Import/ImportLogger.php
- site/src/Cash/MessageHandler/Import/CashFileImportHandler.php
- site/src/Cash/Command/SoftDeleteCompanyTransactionsCommand.php
