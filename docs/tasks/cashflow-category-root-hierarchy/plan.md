# Пользовательские корневые категории ДДС

## Контекст и решения

- Обычная категория ДДС может быть корневой (`parent = null`) или дочерней.
- Обычным категориям разрешены системные родители только `CF_OP`, `CF_FIN`, `CF_INV`.
- `CF_TECH`, `CF_TECH_IN`, `CF_TECH_OUT` и `CF_UNALLOC` не принимают пользовательских потомков.
- `TECHNICAL` зарезервирован для системных категорий.
- При переносе обычной категории в корень сохраняется её текущий `flowKind`; пользователь может изменить его явно.
- Мигратор системной структуры больше не перемещает пользовательские корни.
- Схема БД, транзакции ДДС и денежные суммы не меняются.

## Baseline

- Base: `30fd264964d3f2309ff180320d471b346244a9f6`.
- Системный `php` недоступен: `make codex-test-unit-filter ...` завершился до запуска тестов (`which php`, exit 1).
- Docker baseline: `CashflowCategoryTest|SaveCashflowCategoryActionTest` — 14 tests, 34 assertions, green.

## Stage 1: Инварианты дерева категорий

Risk: HIGH-LOCAL
owner_gate: no
release_candidate: no
independently_deployable: no
stage_base_commit: `30fd264964d3f2309ff180320d471b346244a9f6`

Definition of Done:

- обычную категорию можно сохранить в корне;
- системную категорию нельзя перемещать или переводить в другой `flowKind`;
- запрещены self-parent, циклы, родитель другой компании и глубина дерева более пяти уровней;
- техническое системное дерево и `CF_UNALLOC` нельзя использовать как родителей обычной категории;
- обычная корневая категория не может иметь `TECHNICAL`;
- текущий `flowKind` сохраняется при отделении дочерней категории в корень;
- миграция и документация в Stage не требуются.

Work items:

- 1.1 — добавить доменные методы проверки допустимого родителя и защиты системного `flowKind`.
- 1.2 — усилить `SaveCashflowCategoryAction`: tenant, cycle и итоговая глубина поддерева.
- 1.3 — добавить unit-тесты всех инвариантов.

Stage checks:

- targeted unit tests Entity + Action;
- `make site-cs-check` или эквивалентный Docker composer target;
- полный внутренний review Stage diff;
- внешний read-only Claude review до `REVIEW_GREEN`.

Reviewer focus:

- финансовая классификация root-категорий;
- защита технических категорий переводов;
- циклы, depth и tenant isolation;
- отсутствие изменений схемы и транзакций.

## Stage 2: UI и MCP-контракт переноса в корень

Risk: MEDIUM
owner_gate: no
release_candidate: no
independently_deployable: no
stage_base_commit: `50f500246bad1d46c8ae7fd04c509c1df38a43de`

Definition of Done:

- форма явно содержит `— Корневая категория —`;
- текущая категория, потомки и запрещённые системные узлы исключены из родителей;
- перенос в корень выполняется одним submit и позволяет оставить либо изменить `flowKind`;
- MCP различает отсутствующий `parentId`, UUID и явный `null`;
- старые MCP-вызовы без `parentId` сохраняют поведение;
- системные поля остаются недоступны для редактирования.

Work items:

- 2.1 — добавить tri-state parent contract в DTO, `CashFacade` и MCP schema/tool.
- 2.2 — обновить parent choices и root/flow-kind UX формы.
- 2.3 — добавить integration и functional coverage, обновить `ARCHITECTURE.md`.

Stage checks:

- `CashFacadeMcpSurfaceTest` и MCP tool tests;
- functional controller/form tests;
- Twig lint и relevant unit/integration/functional suites;
- полный внутренний review Stage diff;
- внешний read-only Claude review до `REVIEW_GREEN`.

Reviewer focus:

- обратная совместимость omitted vs explicit null;
- IDOR и company scope;
- серверная валидация независимо от UI/JS;
- корректное наследование `flowKind`.

## Stage 3: Безопасный мигратор и release readiness

Risk: HIGH-LOCAL
owner_gate: yes
release_candidate: yes
independently_deployable: yes
stage_base_commit: `<Stage 2 commit>`

Definition of Done:

- мигратор только создаёт/проверяет канонические системные категории;
- существующие обычные корни не перемещаются и повторный запуск идемпотентен;
- обычные root с legacy `TECHNICAL` видны как read-only предупреждение и автоматически не исправляются;
- CLI, тесты и документация соответствуют новому контракту;
- полный relevant suite, оба review и CI зелёные;
- изменения находятся в одном Draft PR и не смержены.

Work items:

- 3.1 — удалить `rootsToMove` из plan/execute и CLI-отчёта.
- 3.2 — добавить агрегированное read-only предупреждение для обычных `TECHNICAL` root и integration tests.
- 3.3 — обновить Cash README/task docs, выполнить финальные проверки и Release Gate.

Stage checks:

- category migrator integration tests;
- all Cash category unit/integration/functional tests;
- `make site-cs-check`, Twig lint и relevant full suites;
- полный внутренний review полного task diff;
- внешний read-only Claude review до `REVIEW_GREEN`;
- Draft PR CI status.

Reviewer focus:

- отсутствие скрытого reparent на повторном execute;
- сохранение канонического технического дерева;
- отсутствие PII/UUID в агрегированном предупреждении;
- rollout/backward compatibility.

## Release и Production Gates

- Final Release Gate: после Stage 3 запросить только owner-решение на Ready/merge; merge автоматически ведёт к production deploy и требует отдельного явного разрешения.
- Read-only production preflight, deploy, UI-перенос категории ИП Лазарева и read-only acceptance — отдельные Production Gate actions.
- Production correction выполняется только через новую форму пользователем с `FINANCE_WRITE`: `parent = root`, `flowKind = OPERATING`.
- Production SQL, migration, queue processing и write smoke не входят в задачу.

## Не менять

- суммы, знаки, валюты и ссылки существующих CashTransaction/CashTransactionSplit;
- схему БД и зависимости;
- чужую ветку `codex/module-access-roles` и её незавершённые файлы;
- известную MCP-семантику очистки `description`, не относящуюся к `parentId`.
