### Stage 5: сквозная приёмка и готовность выпуска — DONE

**Risk:** HIGH-LOCAL. **Stage base commit:** `89ec6ea49`.
**Work items:** 5.1, 5.2.

#### Результат

Фиксированные A1–A10 дополнены owning concurrency tests A11. Матрица P01–P24
закрыта с привязкой к наблюдаемым результатам в [acceptance.md](../acceptance.md).
Существующий ClockInterface делает московское today воспроизводимым в acceptance;
правила производственного SystemClock не изменены.

Усилена независимая проверка первичных фактов: общий набор допустимых сочетаний
вида/корзины/источника применяется при создании, проведении и replay. Проверяются
cutoff/today/eventSequence/snapshot, согласованность документов и позиций;
активный Cash сверяется с фактическими Money/контрагентом/направлением/датой,
Finance — с допустимостью/контрагентом. Полная сумма Finance остаётся независимой
от ОПиУ. Verify и execute используют один порядок source locks. Валидная история
закрытого периода допускает rebuild, первичные документы не переписываются.

Изменены Settlement policies/actions/projection DTO/query, добавлены AcceptanceTest
и BaseDocumentPolicyTest. В существующий ModuleBoundaryRules добавлена одна запись
Settlement: папка и механизм архитектурных тестов уже существовали до задачи.
Три устаревших PHPStan ignore удалены после устранения соответствующих ошибок
в этой задаче; baseline не регенерировался и не вырос. Обновлены текущие контракты
ARCHITECTURE, приёмка/план/checkpoint/handoff.

#### Definition of Done

- [x] Матрица P01–P24 подтверждена фактическими результатами.
- [x] Новая миграция на пустой и существующей локальной схеме не меняет прежние источники.
- [x] Сквозные даты не зависят от времени запуска тестов.
- [x] Повреждённые первичные факты отклонены без изменения производных записей.
- [x] Fresh full-task internal review: нет открытых BLOCKER/IMPORTANT.
- [x] Общие gates завершены и результаты записаны.
- [x] Итоговый внешний round завершён по marker/fix policy.
- [x] Handoff, commit/push/PR Ready, запрос migration+deploy.

#### Проверки

Baseline Stage5: Stage4 targeted **144 tests / 725 assertions PASS**, focused
CS/PHPStan PASS. Correct RED: Nov8 reversal игнорировал test Clock; после injection
acceptance9/48 PASS. Расширенная acceptance21/107 PASS. Восемь повреждённых
согласованных document+item сначала дали8failures; затем green.

Итоговый модуль: **153 tests / 719 assertions PASS** (Unit/Integration/Functional
Settlement, без отдельных legacy view tests Stage4). Focused10изменённыхPHPfiles
CS/PHPStan PASS. Миграция: свежая схема307; schema1/4 PASS. Схема306 с Cash/Finance:
после307 точные материальные snapshots совпадают, все6Settlement-таблиц пусты.

Первый full Make остановился на3unmatched ignores PHPStan, исправлены только эти
устаревшие записи. Повторный full PHPStan: **3837files, no errors**.
Full CS нашёл одну trailing comma в task AllocationTest; исправлено, повтор PASS.
Strict types3873files PASS; unit4513/25298 PASS. Первый полный8400/47261
закончился1error/4failures: четыре причины в task diff исправлены. Девять mixed
routes теперь GET READ/POST inline WRITE; readonly preview отдельно требует fresh
READ (его RED403→green доказан новым тестом обоих sources). Event sequence поля
используют общий стабильный int DBAL type, SQL BIGINT не меняется. Для transfer
legs восстановлены preflight guards до ORM transaction, повторная проверка под
блокировкой оставлена. Legacy DTO строка31 сохраняется, Money сравнивает сумму;
unordered split identity assertion сравнивает тот же набор ID, а не порядок reload.
Итоговый failed-gates+Settlement+legacy source set: **191/942 PASS**.

После checkpoint dbc31b643 в feature влит актуальный master407ec649c, merge
commit a3e9f02a5. В test services сохранены оба добавления, в Changelog обе стороны;
Settlement получил2.155. Производственный task code конфликтов не имел. Fresh
bounded merge review clean. Заключительные Make gates на merged tree:
PHPStan3860files PASS; CS/strict3896files PASS; unit **4547/25384 PASS**;
полный набор **8485/47654 PASS**,6m57.816s,780.5MB. Unit mock прежнего once
обновлён до atLeastOnce для двух законных preflight/locked проверок; assertions
единственного batch/recalculation и результата сохранены. Focused PHPStan этого
последнего unit файла PASS. Последняя full команда exit0, новых ошибок нет.
Логи: /tmp/vf-settlement-merged-final-gates.log и -merged-final-tests.log.

Повторная проверка окончательной миграции (с party index): fresh_final307 PASS,
schema **1/1 PASS**; migration_final306→307:36SQL, snapshots прежних источников
совпадают и все6новых таблиц пусты. Ранний schema-command с неверным путём завершился
file-not-found; исправлен путь, это не ошибка приложения.

Make запускается через `/tmp/vf-settlement-compose`, который исполняет те же CLI
recipes в уже работающем worktree test container. `-o site-composer-install`
пропускает только переустановку vendor: composer.lock совпадает с mounted vendor
checkout по SHA256. Cache/DB/migration/test prerequisites не пропускаются.

#### Internal review

Stage5 initial: B0/I1 fixed — replay согласованно испорченного основания мог создать
аванс без Cash. Red8→green доказан, bounded rereview no B/I.
Fresh whole-task reviewer: complete task diff, включая untracked tests;
**B0/I0/MINOR1 fixed** (устаревшая промежуточная prose ARCHITECTURE).
Review проверил scope/company/auth, Money/history, guards/locks/idempotency,
replay/migration/report/forms/UI. Без write/production доступа.
После full gates ещё4IMPORTANT исправлены; bounded reviewer нашёл previewREAD
IMPORTANT, исправлен и повторно проверен. Итого Stage5 internal B0/I6 fixed,
суммарно по задаче internal B0/I19 fixed. Открытых BLOCKER/IMPORTANT нет.

#### External review

Required: yes, final handoff. Round1 от task base `4fdad15c9`: B0/I1/MINOR2.
HTML Finance delete500 исправлен, RED500→green; добавлен party index в migration
и schema extension. N guardqueries в maintenance — FOLLOW-UP: пакетное source-owned
event API, без запрещённого Cash→Settlement SQL. Fixed without re-run по policy,
BLOCKER не было. Fresh bounded rereview исправлений: no open findings.

#### Риски и продолжения

Production не затронут; Version20261106183000 требует отдельного одобрения
миграции и выпуска. Down запрещён ради сохранности финансовой истории; rollback
кода сохраняет новые таблицы. RUB only, никаких автоматических старых долгов.
Enhanced DS counterparty adapter и функциональность за пределами D1–D7 — follow-up.

Next: PR Ready; единственный запрос production migration+deploy по release.md.
Без одобрения не merge, не запускать production commands.
