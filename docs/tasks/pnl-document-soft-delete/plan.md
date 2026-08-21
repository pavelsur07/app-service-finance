# Plan — P&L document soft delete

## Scope

Ручное удаление в «ОПиУ → Операции ОПиУ» становится мягким по аналогии с
транзакциями ДДС. Удалённые документы не участвуют в рабочих списках, отчётах,
регистрах и экспортах, но сохраняют свои операции и могут быть восстановлены из
отдельной вкладки. Техническое удаление документов при переоткрытии месяца
Marketplace остаётся физическим.

## Stage 1: Lifecycle and persistence

Risk: HIGH-LOCAL
owner_gate: no
release_candidate: no
independently_deployable: no
stage_base_commit: `2a331ee348244cb6f59713d84c63424f4651c413`

Definition of Done:

- `Document` хранит nullable-маркеры удаления; миграция обратима.
- company-scoped Actions мягко удаляют и восстанавливают документ.
- Дочерние операции сохраняются, связанные ДДС-распределения и дневной регистр пересчитываются.
- Восстановление не допускает перепривязку сверх доступной суммы ДДС.
- Технический Marketplace lifecycle продолжает физическое удаление.

Work items:

- 1.1 — добавить schema/entity lifecycle и company-scoped repository queries.
- 1.2 — реализовать soft-delete/restore Actions и пересчёты.
- 1.3 — покрыть lifecycle, tenant isolation и Marketplace hard-delete тестами.

Stage checks:

- Doctrine mapping/migration checks.
- Finance lifecycle unit/integration tests.
- PHP syntax and focused style checks.

Reviewer focus:

- Целостность операций, ДДС allocation и регистра ОПиУ.
- Tenant isolation и сохранение технической hard-delete семантики.

## Stage 2: Active-only read boundaries

Risk: MEDIUM
owner_gate: no
release_candidate: no
independently_deployable: no
stage_base_commit: `90c9dabf829f6dc03e9156cae2a1bbd6ea68c085`

Definition of Done:

- Удалённые документы отсутствуют в основном списке, отчётах и массовом JSON-экспорте.
- Прямые show/edit/copy/JSON маршруты удалённого документа отвечают 404.
- Страница связанной транзакции ДДС показывает только активные документы ОПиУ.

Work items:

- 2.1 — закрыть ORM/SQL read paths фильтром `deleted_at`.
- 2.2 — закрыть прямые маршруты документа.
- 2.3 — добавить функциональные regression tests.

Stage checks:

- Finance controller/export/report tests.
- PHP syntax and focused style checks.

Reviewer focus:

- Полнота read boundaries, отсутствие IDOR и изменение только manual lifecycle.

## Stage 3: Manual UI lifecycle and release candidate

Risk: MEDIUM
owner_gate: yes
release_candidate: yes
independently_deployable: yes
stage_base_commit: `35f637a03e34fbe5d35d063cf382262a59887108`

Definition of Done:

- Ручной POST delete вызывает soft-delete Action и пишет actor/reason.
- В UI есть tabs «Операции ОПиУ | Удалённые» и company-scoped deleted list.
- Удалённый документ можно восстановить защищённым CSRF POST; доменная ошибка показана пользователю.
- Функциональные тесты покрывают UI-разделение, CSRF, tenant isolation и lifecycle.
- Архитектурная документация описывает различие manual soft delete и Marketplace hard delete.

Work items:

- 3.1 — подключить delete/restore routes к Actions.
- 3.2 — добавить tabs, deleted page, пагинацию и UI feedback.
- 3.3 — добавить functional coverage и документацию.
- 3.4 — выполнить task-wide checks и оба review-gate цикла.

Stage checks:

- Functional controller/access tests.
- Finance lifecycle, report and export regression tests.
- Doctrine validation, Twig lint, container lint, PHP syntax/style checks.

Reviewer focus:

- CSRF/write access, tenant isolation, restore failure behavior и отсутствие скрытых active reads.

## Gates and exclusions

- Final Release Gate: владелец решает, переводить ли Draft PR в Ready/merge после зелёных проверок и review.
- Production Gate: merge, deploy и любые production migrations не входят в задачу и требуют отдельного явного разрешения.
- Не меняются финансовые формулы, статусы документов, locked-period rules, Marketplace regeneration flow и глобальная Doctrine-фильтрация.
