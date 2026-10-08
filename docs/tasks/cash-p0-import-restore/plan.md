# cash-p0-import-restore: P0-дефекты модуля Cash — файловый импорт и откат массового soft delete

Источник: аудит `site/src/Cash` от 2026-10-08 (чат с Владельцем), пункты P0-1…P0-3.
Поручение Владельца: «начни с P0, сначала тесты». Порядок внутри каждого Stage:
регрессионный тест → доказанно красный на старом коде (вывод в Stage Report) →
фикс → зелёный.

Класс: **Large** (Messenger-handler, финансовые данные, импорт). Миграций нет;
если по ходу понадобится миграция — это выход из scope, см. «Границы».
Ветка: `fix/cash-p0-import-restore` от `master` @ `56901219`. Один Draft PR.

Baseline: целевой набор Cash-тестов — OK (40 tests, 153 assertions), команда в
`checkpoint.md`.

**Итог Phase 0 (данные прода — в `checkpoint.md`):** главный дефект шире P0-2.
С 2026-02-08 *каждый* файловый импорт, создавший хотя бы одну строку, зависает в
`processing` (66 задач, 3 компании). Строки и остатки записываются, но задача и
журнал не закрываются. Импорты больше 200 строк обрезаются на 200 (23 задачи).
P0-1 и P0-3 в данных не наблюдались — латентные дефекты кода.

## Факты, установленные при планировании (перепроверить, не передоказывать)

- Doctrine ORM 3.6.3: `EntityManager::clear()` без параметров
  (`vendor/doctrine/orm/src/EntityManager.php:415`). PHP молча игнорирует лишний
  аргумент, поэтому `clear(CashTransaction::class)` очищает **весь** UnitOfWork.
  ORM 3.x стоит с начала проекта (3.5.0 → 3.6.1 → 3.6.3).
- `CashFileImportService::flushBatch()` (`site/src/Cash/Service/Import/File/CashFileImportService.php:399-405`)
  после первой пачки в 200 строк отсоединяет `$company`, `$moneyAccount`,
  `$systemProject`, `$importLog`, `$job`. На следующем `flush` новая
  `CashTransaction` ссылается на отсоединённые сущности без cascade persist.
  Ожидаемо `ORMInvalidArgumentException` «A new entity was found through the
  relationship…». **Гипотеза: импорт файла длиннее 200 валидных строк не работает.**
- `CashTransaction` несёт `uniq_cashflow_import (company_id, import_source, external_id)`:
  полный индекс, без `WHERE deleted_at IS NULL` (`migrations/Version20251112090000.php:27`).
  Файловый импорт пишет `external_id = trim(docNumber)` (`CashFileImportService.php:196-201`),
  а дедупликацию проверяет только по `dedupeHash` (`:166`). Номер документа
  повторяется (нумерация с начала года, несколько счетов одной компании, ранее
  импортированная или soft-deleted операция). Тогда `flush` бросает
  `UniqueConstraintViolationException`, и EntityManager закрывается.
- `CashFileImportHandler` (`site/src/Cash/MessageHandler/Import/CashFileImportHandler.php`)
  сначала коммитит статус `processing` (`:45-47`), потом ловит исключение
  импорта (`:56-60`) и вызывает `find` + `flush` на **закрытом** EM (`:62-85`).
  `flush` бросает, сообщение уходит в retry, retry выходит на проверке
  `STATUS_QUEUED !== status` (`:39`). Итог: задача навсегда в `processing`,
  ошибка не видна (нет `logger->error`). Дополнительно `finally` в
  `readAndPersist` (`:249-253`) вызывает `ImportLogger::finish()` → `flush` на
  закрытом EM. Это исключение маскирует исходное.
- Значение `external_id` у строк с `import_source = 'file'` никто не читает.
  `CashFacade::createTransaction` (`:417-460`) работает только с
  `importSource`/`externalId`, которые передал вызывающий; литерала `'file'` в
  вызывающих нет (grep `importSource: 'file'` пуст). Перепроверить в WI 2.1.
- `SoftDeleteCompanyTransactionsCommand --restore` снимает soft delete со
  **всех** удалённых операций компании (`site/src/Cash/Command/SoftDeleteCompanyTransactionsCommand.php:146-150`).
  Маркер массовой операции `deleted_by = 'cli:app:cash:soft-delete-company-transactions'`
  (const `ACTOR`, `:56`) в restore-предикатах не используется. Те же
  несимметричные предикаты стоят в `countByDeletedState`, `hasLockedRows`,
  `minOccurredAt` и в финальной верификации.
- В тестах: DAMA DoctrineTestBundle (rollback на тест), базовый класс
  `App\Tests\Support\Kernel\IntegrationTestCase`, атрибут `#[SkipDatabaseRollback]`
  для тестов, которым нужен реальный commit или закрытие EM. Образцы:
  `tests/Integration/Cash/Service/Import/File/CashFileImportWorkerStorageTest.php`
  (полная подготовка компании, счёта, системного проекта и ЦФО; CSV в
  `ObjectStorageInterface`), `tests/Unit/Cash/MessageHandler/Import/CashFileImportHandlerTest.php`,
  `tests/Integration/Cash/Command/SoftDeleteCompanyTransactionsCommandTest.php`.
- Паттерн восстановления закрытого EM в репозитории:
  `ManagerRegistry::resetManager()` + `getManager()`
  (`site/src/Marketplace/MessageHandler/ProcessRawDocumentStepMessageHandler.php:147-152`).

## Решения, принятые при планировании

1. **P0-2: отсоединять только своё, а не очищать всё.** В `flushBatch()`
   вызывать `detach()` для созданных в пачке `CashTransaction` и новых
   `Counterparty` (их собирать в массив пачки), затем сбрасывать
   `counterpartyCache`. Компания, счёт, проект, `ImportLog` и job остаются
   managed. Альтернатива `clear()` с повторной загрузкой всех ссылок длиннее и
   хрупче. Если `detach()` в ORM 3.6.3 помечен deprecated, перепроверить в
   vendor; при deprecation взять альтернативу и записать причину.
2. **P0-1: файловый импорт перестаёт писать `docNumber` в `external_id`.**
   Номер уже хранится в `doc_number` (`setDocNumber`, `:185`). Защита от дублей
   остаётся на `dedupeHash` — это действующая семантика файлового импорта.
   Пропускать строку по совпавшему номеру нельзя: это молча потеряет реальные
   денежные операции (номера повторяются между годами и счетами). Старые строки
   сохраняют свой `external_id`; миграция и backfill не нужны.
3. **Обработчик падения импорта** приводит задачу в `failed` даже при закрытом
   EM: `isOpen()` → `resetManager()` → свежий `find` → `fail()` → `flush`.
   Плюс один `logger->error` с `jobId`, `companyId` и классом исключения, без
   тела строки, ИНН и ФИО. `ImportLogger::finish()` больше не маскирует исходное
   исключение. Повторной попытки (retry) нет, как и сейчас: импорт не
   идемпотентен на уровне пачек.
4. **P0-3: restore трогает только строки с `deleted_by = ACTOR`.** Все
   restore-предикаты получают `AND deleted_by = :actor`. В dry-run выводится и
   число вручную удалённых строк, которые restore не тронет. Delete-ветку не
   менять.
5. Бизнес-правила не меняются: частичный импорт при падении на середине
   остаётся частичным (all-or-nothing — follow-up и решение Владельца).

## Границы (scope)

- Входит: `CashFileImportService`, `CashFileImportHandler`, при необходимости
  `ImportLogger`, `SoftDeleteCompanyTransactionsCommand`, их тесты.
- Тот же ложный `clear(CashTransaction::class)` стоит в
  `ApplyAutoRulesForTransactionHandler.php:80,161` и
  `EnqueueAutoRulesForRangeHandler.php:95,112`. Поведение не менять. Заменить
  на явный `clear()` (идентично по факту, убирает вводящий в заблуждение
  аргумент) только если это не ломает тесты. Иначе — FOLLOW-UP.
- Не входит: P1/P2 аудита (экспорт формул, логи Альфы, маршрутизация Messenger,
  1С), all-or-nothing импорт, миграции, починка застрявших задач на проде
  (это SQL write → §3.3, отдельный запрос Владельцу с цифрами из Phase 0).

## Phase 0 (до кода)

1. `git status` — ветка `fix/cash-p0-import-restore`. Не трогать и не коммитить
   чужие untracked: `.secret/`, `docs/integrations/`,
   `site/bin/capture-wb-inventory.sh`.
2. Baseline:
   `docker compose exec site-php-cli vendor/bin/phpunit tests/Unit/Cash/Service/Import/File tests/Unit/Cash/MessageHandler/Import tests/Integration/Cash/Service/Import/File tests/Integration/Cash/Command/SoftDeleteCompanyTransactionsCommandTest.php tests/Integration/Cash/MessageHandler`.
   Точную форму вызова взять из `Makefile` и `composer.json` (`test:integration`);
   если контейнер не поднят, использовать `make site-test-wait-db` и
   `docker compose run`. Записать результат и pre-existing failures.
3. **Подтверждение дефекта в данных прода (read-only)** по
   `docs/maintenance/prod-access.md`: `ssh -o BatchMode=yes -o IdentityAgent=none vf-prod-codex "sudo /usr/local/bin/codex-psql-ro -c \"…\"" < /dev/null`.
   Сначала проверить имена колонок (`\d cash_file_import_jobs`,
   `\d import_log`). Запросы:
   - `SELECT status, count(*) FROM cash_file_import_jobs GROUP BY 1;`
   - зависшие: `SELECT count(*), min(started_at), max(started_at) FROM cash_file_import_jobs WHERE status='processing' AND started_at < (now() AT TIME ZONE 'Europe/Moscow') - interval '1 hour';`
   - причины падений без PII: `SELECT left(error_message, 120) AS err, count(*) FROM cash_file_import_jobs WHERE status='failed' GROUP BY 1 ORDER BY 2 DESC LIMIT 20;`
     Искать `new entity was found`, `uniq_cashflow_import`, `EntityManager is closed`.
   - верхняя граница успешных файловых импортов:
     `SELECT max(created_count), count(*) FILTER (WHERE created_count > 200) FROM import_log WHERE source = '<источник файлового импорта, взять из кода ImportLogger::start в CashFileImportController>';`
     Успешные логи с `created_count > 200` опровергают гипотезу P0-2. Тогда
     разобраться, почему, и записать вывод до кода.
   - P0-3: `SELECT company_id, count(*) FILTER (WHERE deleted_by = 'cli:app:cash:soft-delete-company-transactions') AS mass, count(*) FILTER (WHERE deleted_at IS NOT NULL AND deleted_by IS DISTINCT FROM 'cli:app:cash:soft-delete-company-transactions') AS manual FROM cash_transaction GROUP BY 1 HAVING count(*) FILTER (WHERE deleted_by = 'cli:app:cash:soft-delete-company-transactions') > 0;`
     Выводить только `company_id` и счётчики, без названий.

   Время в БД — МСК без зоны. Если вызов блокирует песочница или auto mode, не
   повторять тот же вызов: записать заблокированную команду в checkpoint и
   продолжать. Регрессионные тесты — основное доказательство, прод-данные —
   подтверждение масштаба. Это не STOP (§3.4 п.5 — сообщить в handoff).
4. Записать цифры «до» в `checkpoint.md`: они нужны для запроса §3.3 по
   застрявшим задачам и для пост-деплойной сверки.

## Stage 1: файловый импорт завершается (`done`) и не обрезается на 200 строках

Risk: HIGH-LOCAL (импорт денежных операций)
stage_base_commit: _записать перед WI 1.1_

Definition of Done:
- **Основной прод-сценарий:** импорт CSV на 1 строку через полный путь
  `CashFileImportHandler` (сообщение → handler) → задача `done`,
  `ImportLog.finishedAt` проставлен, `createdCount = 1`. Сейчас на проде такая
  задача остаётся `processing`. Тест обязан быть красным на старом коде;
  существующий `CashFileImportWorkerStorageTest` этого не ловит, потому что
  зовёт сервис напрямую и не проверяет `finishedAt`.
- Импорт CSV на 201 и 401 валидную строку создаёт ровно 201 и 401 операцию.
  `ImportLog.createdCount` совпадает, `finishedAt` проставлен.
- `ImportLogger::finish()` не полагается на `flush($log)`: в ORM 3 аргумент
  игнорируется и flush идёт по всему UnitOfWork. Достаточно, чтобы
  `ImportLog` оставался managed; отдельный `persist()` уже managed-сущности
  убрать или оставить безвредным.
- Повторный импорт того же файла создаёт 0 операций и даёт
  `skippedDuplicate = N`.
- Новые контрагенты не дублируются через границу пачки (одно имя в строках
  1 и 250 → один `Counterparty`).
- Регрессионный тест красный на старом коде; вывод в Stage Report.
- Исключено: изменение `batchSize`, семантики дедупликации, автоправил.

Work items:
- 1.1 — Тесты (красные). Сначала handler-уровень, 1 строка:
  `tests/Integration/Cash/MessageHandler/Import/CashFileImportHandlerFlowTest.php`.
  Job со статусом `queued` и `ImportLog` из `ImportLogger::start()`, вызов
  handler'а из контейнера, проверка статуса задачи и `finishedAt` журнала
  после `$em->clear()` и перечитывания. Ожидаемая причина на старом коде:
  `UniqueConstraintViolation` по PK `import_log` или «EntityManager is
  closed». Записать фактическую. Затем граница пачки:
  `tests/Integration/Cash/Service/Import/File/CashFileImportBatchBoundaryTest.php`.
  Подготовку взять из `CashFileImportWorkerStorageTest`, вынести в private
  helper. CSV генерировать в тесте: строки с разными суммами или назначениями,
  чтобы `dedupeHash` различался; дата в прошлом. Вызов
  `CashFileImportService::import($job)`. Кейсы: 201 строка; повторный импорт;
  контрагент через границу пачки. Если DAMA мешает закрытием EM, поставить
  `#[SkipDatabaseRollback]` + `resetDb()` по образцу существующих тестов.
  Прогнать, сохранить текст исключения в checkpoint.
- 1.2 — Фикс `flushBatch()` по решению 1: массив сущностей пачки, `flush()`,
  `detach()` каждой, сброс массива и `counterpartyCache`. Удалить ложные
  `clear(X::class)`.
- 1.3 — Зелёный прогон 1.1 и baseline-набора. Отдельно проверить, что
  `recalculateDailyRange` (`:247`) получает managed `$company`/`$moneyAccount`:
  тест должен проверить, что дневные остатки пересчитаны (в
  `MoneyAccountDailyBalance` есть строка на дату операции).
- 1.4 — Автоправила: заменить `clear(CashTransaction::class)` на `clear()` в
  `ApplyAutoRulesForTransactionHandler` и `EnqueueAutoRulesForRangeHandler`,
  прогнать их интеграционные тесты. Красные → откатить WI и записать FOLLOW-UP.

Stage checks:
- целевые тесты 1.1 + baseline-набор;
- `make site-cs-check` (или php-cs-fixer по изменённым файлам);
- PHPStan по изменённым файлам с `maximumNumberOfProcesses: 2`
  (OOM на dev-машине, см. memory); гейты по одному.

Reviewer focus:
- после пачки нет других managed-зависимостей, которые должны отсоединяться
  (`rawData`, `ImportLog`);
- память на больших файлах: что остаётся в identity map;
- контрагент, созданный в пачке N, корректно находится в пачке N+1 после
  detach (поиск через БД).

## Stage 2: повторный номер документа не валит импорт, а падение доходит до `failed`

Risk: HIGH-LOCAL (Messenger-handler, финансовые данные)
stage_base_commit: _записать перед WI 2.1_

Definition of Done:
- CSV с двумя строками с одинаковым номером документа (разные суммы) → обе
  операции созданы, задача `done`.
- Файл, номер из которого уже есть у ранее импортированной или soft-deleted
  операции компании с `import_source='file'` и `external_id=<номер>`
  (легаси-данные) → операция создана.
- Любое исключение импорта, в том числе с закрытым EM, → задача `failed` с
  читаемым `errorMessage` (существующий формат и тест
  `testFailedImportStoresReadableErrorWithoutDebugMarkers` не ломать), один
  `logger->error` с `jobId`/`companyId`. `ImportLog.finishedAt` проставлен,
  если это возможно; исходное исключение не маскируется.
- Обработчик не бросает наружу после успешной фиксации `failed`, retry не
  нужен.
- Регрессионные тесты красные на старом коде; вывод в Stage Report.
- Исключено: all-or-nothing, изменение `dedupeHash`, маршрутизация Messenger.

Work items:
- 2.1 — Перепроверка: grep по `site/src` и `site/tests` на `'file'` как
  `importSource` и на чтение `external_id` для файлового источника (Facade,
  MCP, Telegram, отчёты, автоправила). Найдётся потребитель → §3.4 п.1 (смысл
  данных), STOP с вариантами.
- 2.2 — Тесты (красные):
  - интеграционный `CashFileImportDuplicateDocNumberTest`: два кейса из DoD
    через полный путь `CashFileImportHandler` (сообщение →
    handler → статус задачи). Предусловие легаси-кейса создать напрямую через
    `CashTransactionBuilder` + `setImportSource('file')` +
    `setExternalId('17')`;
  - юнит в `CashFileImportHandlerTest`: импорт бросает, `isOpen()` = false →
    ожидаем `resetManager()`, `fail()`, `flush` на новом EM и вызов
    `logger->error`. Зафиксировать текущий вывод: на старом коде задача
    остаётся `processing`.
- 2.3 — Фикс сервиса: убрать `setExternalId($trimmedDocNumber)` (решение 2).
  В `finally` вызывать `importLogger->finish()` только при `isOpen()`, иначе
  оставить это обработчику. Комментарий одной строкой: почему не
  `external_id`.
- 2.4 — Фикс обработчика: внедрить `ManagerRegistry` и `LoggerInterface`;
  ветка падения по паттерну `ProcessRawDocumentStepMessageHandler::recordStepFailure`.
  После reset доставать job и его `ImportLog` из нового EM, ставить
  `finishedAt` через `ImportLogger` (проверить, что внедрённый в него EM после
  `resetManager()` указывает на новый; если нет — проставить напрямую через
  новый EM). Добавить `info` о старте и финише с `jobId`/`companyId` (правило
  CLAUDE.md для MessageHandler).
- 2.5 — Зелёный прогон 2.2, тестов Stage 1 и baseline-набора.

Stage checks: как в Stage 1.

Reviewer focus:
- нет пути, на котором задача остаётся `processing` (исключение внутри
  `fail()`/`flush` после reset);
- в логе нет `getMessage()` с SQL-параметрами или данными строки: только класс
  исключения и ID. `errorMessage` задачи остаётся как есть (существующий
  контракт UI);
- отсутствие `external_id` не ломает уникальность и поиск для других
  источников (`import_source` у них свой).

## Stage 3: `--restore` откатывает только массовое удаление

Risk: HIGH-LOCAL (массовое изменение финансовых данных)
stage_base_commit: _записать перед WI 3.1_

Definition of Done:
- Операция, удалённая вручную (`deleted_by` = user id или NULL) до или после
  массового удаления, после `--restore --execute` остаётся удалённой. Операции
  с `deleted_by = ACTOR` восстановлены, пересчёт остатков и инвалидация кэша
  отработали.
- Dry-run `--restore` считает только `ACTOR`-строки и отдельно выводит число
  вручную удалённых, которые не будут тронуты.
- Вручную удалённая строка в закрытом периоде не блокирует restore.
  `ACTOR`-строка в закрытом периоде блокирует, как и сейчас.
- Верификация «осталось в исходном состоянии» считает только `ACTOR`-строки.
- Старые тесты команды зелёные; новые красные на старом коде.
- Docblock и описание опции `--restore` отражают новое поведение.
- Исключено: delete-ветка, формат `deleted_by`, UI-удаление.

Work items:
- 3.1 — Тесты (красные) в `SoftDeleteCompanyTransactionsCommandTest`:
  `testRestoreKeepsManuallyDeletedTransactions`,
  `testRestoreDryRunCountsOnlyMassDeleted`,
  `testManuallyDeletedRowInLockedPeriodDoesNotBlockRestore`. Ручное удаление
  моделировать так же, как UI: `markDeleted()` с user id; проверить сигнатуру
  в `CashTransaction`.
- 3.2 — Фикс: параметр `actor` во все restore-предикаты
  (`countByDeletedState`, `hasLockedRows`, `minOccurredAt`, `UPDATE`,
  верификация). Для delete-ветки предикаты прежние. Вывод счётчика вручную
  удалённых. Обновить docblock: симметрия теперь «delete ставит ACTOR, restore
  снимает только ACTOR».
- 3.3 — Зелёный прогон всего `SoftDeleteCompanyTransactionsCommandTest`.

Stage checks: как в Stage 1.

Reviewer focus:
- `deleted_by IS NULL` у старых ручных удалений не попадает в restore;
- `minOccurredAt` по `ACTOR`-строкам: диапазон пересчёта не сужен ошибочно;
- идемпотентность повторного restore.

## Handoff

1. Полные гейты по одному (OOM): `make site-stan`, `make site-cs-check`,
   `make site-cs-strict-types`, `make site-test-unit`, `make site-test`
   (интеграционные пути затронуты). Baseline PHPStan не растёт.
2. Финальный internal review в **свежей сессии**: только этот plan, diff от
   `56901219`, чеклист `docs/workflow/stage-report.md`.
3. External review (Large → один раунд на handoff, §7.2):
   `site/bin/external-review.sh 56901219 --effort medium --context <путь к файлу>`.
   `--context` принимает **путь к файлу**, не строку: содержимое (исправленное
   внутренне, факты Phase 0) положить в `site/var/external-review/context-cash-p0.md`.
   Скрипт включает untracked-файлы, поэтому находки по чужому
   `site/bin/capture-wb-inventory.sh` отклонять с записанной причиной.
   Механика — `docs/workflow/external-review.md`.
4. `handoff.md`. В PR-описании:
   - три дефекта с доказательством «красный → зелёный»;
   - цифры прода из Phase 0;
   - FOLLOW-UP:
     - починка 66 застрявших `processing`-задач на проде — SQL write, §3.3,
       отдельный запрос с точным `UPDATE` и числом строк. 43 задачи с
       записанными строками → `done` + `finished_at`; 23 задачи по 200 строк —
       частичные: `failed` с сообщением и список файлов/компаний для
       перезагрузки пользователями (разбивка — в `checkpoint.md`);
     - 15 задач `queued` с февраля;
     - GlitchTip issue 414 (`uniq_cts_tx_category` в
       `CashTransactionAutoRuleController`);
     - 1 день без строки остатка у счёта `10d8d659…`;
     - all-or-nothing импорт;
     - P1/P2 аудита.
5. PR Ready → единственный вопрос §3.2. Миграции нет, значит обычный «merge and
   deploy».
6. После деплоя (в рамках того же одобрения) read-only сверка:
   - новых задач в `processing` старше часа нет;
   - падения импорта, если есть, видны в GlitchTip через `gt.sh`.
