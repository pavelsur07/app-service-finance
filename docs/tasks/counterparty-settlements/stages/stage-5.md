### Stage 5: сквозная приёмка и готовность выпуска — IN PROGRESS

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
- [ ] Общие gates завершены и результаты записаны.
- [ ] Итоговый внешний round завершён по marker/fix policy.
- [ ] Handoff, commit/push/PR Ready, запрос migration+deploy.

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
Остальные общие gates выполняются, результаты будут добавлены после завершения.

Make запускается через `/tmp/vf-settlement-compose`, который исполняет те же CLI
recipes в уже работающем worktree test container. `-o site-composer-install`
пропускает только переустановку vendor: composer.lock совпадает с mounted vendor
checkout по SHA256. Cache/DB/migration/test prerequisites не пропускаются.

#### Internal review

Stage5: B0/I1 fixed — replay согласованно испорченного основания мог создать
аванс без Cash. Red8→green доказан, bounded rereview no B/I.
Fresh whole-task reviewer: complete task diff, включая untracked tests;
**B0/I0/MINOR1 fixed** (устаревшая промежуточная prose ARCHITECTURE).
Review проверил scope/company/auth, Money/history, guards/locks/idempotency,
replay/migration/report/forms/UI. Без write/production доступа.

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

Next: завершить gates/external round, handoff по release.md.
