# Взаиморасчёты с контрагентами — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Репозиторный AGENTS.md имеет приоритет: Work item не является Stage gate; review/commit/push выполняются по Stage, без остановок между этапами.

**Goal:** Связать основания обязательств и фактические платежи и показать объяснимые остатки и изменения по контрагентам.

**Architecture:** Новый модуль Settlement хранит расчётные документы, открытые позиции и распределения. Его регистр — восстанавливаемое представление проведённых документов, классификаций, корректировок и распределений. Cash/Finance передают собственные факты через Facade/DTO; существующее разнесение ДДС в ОПиУ не становится погашением задолженности.

**Tech Stack:** PHP / Symfony 7.4, Doctrine, PostgreSQL, существующий Money, Twig/Symfony Forms и дизайн-система проекта, PHPUnit. Без новых зависимостей.

**Spec:** [TASK.md](TASK.md), включая решения D1–D7 и примеры A1–A11.

**Status:** Проект плана от 2026-11-06. Ни один этап реализации не выполнен.
**Repository inspection base:** `4fdad15c974830d13ff9342d1f40a526f3b5bd33` (`origin/master`).
**Planning baseline:** `git diff --check` — exit 0, документационные изменения.
PHP/DB/frontend baseline не запускался: текущая задача создаёт только план.

## Global Constraints

- Symfony 7.4, Doctrine, PostgreSQL, существующий Money; новых зависимостей нет.
- Требование владельца: все денежные значения и вычисления в рамках задачи — через
  существующий `App\Shared\Domain\ValueObject\Money`.
  Это обязательное правило для всех Stage, независимо от согласования D1–D7.
- `Money` обязателен для сумм оснований, платежей, авансов, распределений, корректировок,
  начальных/текущих остатков, движений регистра, просрочки и итогов отчётов;
  Entity, PHP DTO, Facade и расчётные сервисы используют один существующий тип.
- Денежная арифметика и сравнения — методами `Money`; не создавать второй тип,
  локальные денежные helpers или вычисления над извлечёнными `amountMinor` в бизнес-логике.
  Не использовать `float`, включая промежуточные вычисления и преобразования legacy-сумм.
- На границах форм/API/экспорта сумма — точная decimal-строка плюс валюта;
  нормализация в `Money` до бизнес-логики, вывод через `toDecimalString()` и точный
  formatter только для отображения. Не передавать деньги JSON-числом и не считать их в JS.
  RUB имеет 2 знака; избыточную точность входа отклонять до `Money::fromString()`.
- В новых Entity использовать `#[ORM\Embedded(class: Money::class)]` и существующий
  `MoneyAmountType`. SQL SUM допустим по точным minor units внутри одной валюты;
  результат проверить на диапазон до преобразования в `Money`, не приводить через float.
- Следовать разделу денежных сумм PATTERNS.md, включая ограничения overflow текущего
  `Money`: не обходить их локальной арифметикой; необходимое расширение общего типа
  оформить отдельной задачей. Ставки/курсы/коэффициенты не являются денежными суммами.
- Каждый запрос ограничен companyId; чужие сущности доступны через Facade и DTO.
- FINANCE_READ для чтения, FINANCE_WRITE для записи; не расширять права пользователей.
- Секреты, production-доступ и live API-вызовы не нужны для реализации.
- Backend и интерфейс в разных Stage; 1 implementation branch / 1 Draft PR.
- Миграции добавляют таблицы; не преобразуют и не удаляют существующие данные.
- Production-миграция требует отдельного именованного одобрения по release.md.

Область первой версии и правила дат/отмен зависят от D1–D7; ниже расписан
рекомендуемый вариант из TASK.md. До его согласования выполняется только Phase 0.
Не считать готовность этого документа разрешением начинать реализацию.

## Review Focus

1. Платёж и распределение учтены дважды: A1/A7, `SettlementAllocationTest`, Work item 3.1.
2. Материальная правка/удаление источника обошли guard через bulk/import/DBAL:
   `SettlementSourceMutationGuardTest`, Work item 2.3, полный реестр write paths.
3. Два параллельных распределения потратили один остаток:
   `SettlementAllocationConcurrencyTest`, Work item 3.1, две реальные DB-сессии.
4. Отмена сегодня изменила исторический отчёт вчера:
   A5, `SettlementReportQueryTest`, Work item 3.2, effective date каждого события.
5. Чужая компания или отозванное право прошли через вспомогательный путь:
   `SettlementAccessTest` + cross-company cases во всех Actions, Work items 1.2/4.2.

## Phase 0 — зафиксировать решения и baseline

- [ ] Получить ответы D1–D7; записать согласованные правила в TASK.md. Если изменился
  scope, скорректировать таблицу этапов, DTO и матрицу приёмки до продуктового кода.
- [ ] Создать/проверить изолированную implementation branch от актуального master.
  Документационный PR этого плана не является implementation PR.
- [ ] Прочитать актуальные AGENTS.md, CLAUDE.md, разделы Facade/Cash/Finance/Balance
  в ARCHITECTURE.md, PATTERNS.md §7/§12/денежные суммы, new-module.md, CLAUDE.frontend.md.
- [ ] Сравнить три образца: `BalanceLedgerService` (проведение/отмена),
  `CreateDocumentFromTransactionAction` (блокировка источника),
  `PaymentPlanMatcher` (существующая связь с оплатами и её ограничения).
- [ ] Проверить локальное окружение, mount рабочей копии в контейнер и отдельную
  test DB. При необходимости подготовить её `make site-test-smoke-init`.
- [ ] Выполнить baseline существующих тестов: `docker compose exec -T -e XDEBUG_MODE=off site-php-cli php bin/phpunit tests/Unit/Cash/Entity/Transaction/CashTransactionAllocationTest.php`;
  затем `docker compose exec -T -e XDEBUG_MODE=off site-php-cli php bin/phpunit tests/Integration/Cash/Application/CreateDocumentFromTransactionActionTest.php`.
  Записать команды, числа тестов и pre-existing failures в checkpoint.md.
- [ ] Создать `checkpoint.md`; перед каждым Stage записывать реальный stage_base_commit.

Не копировать .env.local и production credentials в новую рабочую копию.
Не запускать `make site-test` до handoff. При повторных тестах использовать running
контейнер `site-php-cli`; `exec` обязан читать именно implementation worktree.

## Карта этапов

| Stage | Результат | Risk | Зависимости |
|---|---|---|---|
| 1 | Документы, позиции, регистр, начальные остатки, корректировки | HIGH-LOCAL | D1–D7, baseline |
| 2 | Проверяемые связи с ДДС/ОПиУ и защита исходных документов | HIGH-LOCAL | Stage 1 |
| 3 | Частичные погашения, авансы, исторические отчёты и сверка | HIGH-LOCAL | Stage 1–2 |
| 4 | Пользовательский интерфейс и сценарии доступа | MEDIUM | Stage 1–3 |
| 5 | Сквозная приёмка, review, миграционный handoff | HIGH-LOCAL | Stage 1–4 |

Один implementation Draft PR на все Stage. После Stage: модульные проверки,
review полного diff этапа, фиксы, Stage Report, commit, push, обновление PR;
переход к следующему Stage автоматически. Внешний review — на handoff и на конце
HIGH-LOCAL Stage с diff >500 строк, согласно AGENTS.md §7.2.

## Карта файлов и ответственности

Все новые пути ниже предлагаются планом, а не объявляются уже существующими.

| Файлы | Ответственность |
|---|---|
| `site/src/Settlement/Entity/SettlementBook.php` | Дата начала и версия учёта компании, сериализация финансовых мутаций |
| `site/src/Settlement/Entity/SettlementDocument.php` | Основание, source snapshot, статус, причина, автор и ключ повтора |
| `site/src/Settlement/Entity/SettlementItem.php` | Открытая позиция обязательства/оплаты/аванса |
| `site/src/Settlement/Entity/SettlementMovement.php` | Производные датированные изменения корзин, ссылка на источник события |
| `site/src/Settlement/Entity/SettlementAllocation.php` | Пара позиций, сумма, effective date; отмена отдельным документом |
| `site/src/Settlement/Entity/SettlementSourceLink.php` | Производная активная связь source → document и дата последней отмены; восстанавливается из истории |
| `site/src/Settlement/Repository/SettlementRepository.php` | Company-scoped загрузка и блокировки; без flush |
| `site/src/Settlement/Domain/SettlementPostingPolicy.php` | Допустимые пары/даты/остатки и построение движений |
| `site/src/Settlement/Application/Service/SettlementRegisterUpdater.php` | Атомарная запись/восстановление производного журнала |
| `site/src/Settlement/Application/*Action.php` | Отдельные сценарии, описанные ниже; граница транзакции |
| `site/src/Settlement/Application/DTO/*.php` | Контракты, перечисленные ниже; readonly, Money для сумм |
| `site/src/Settlement/Infrastructure/Query/SettlementReportQuery.php` | Сводка и карточка на дату из согласованного снимка |
| `site/src/Settlement/Facade/SettlementFacadeInterface.php`, `SettlementFacade.php` | Публичные read contracts, без чужих Entity |
| `site/src/Settlement/Controller/`, `Form/`; `site/templates/settlement/` | UI Stage 4, точные имена ниже |
| `site/migrations/Version20261106000000.php` | Аддитивная схема; если имя занято к реализации — новое время, обновить план |
| `site/config/routes.yaml`, `packages/doctrine.yaml`, `packages/twig.yaml`, `services.yaml` | Регистрация модуля и Facade binding |
| `site/src/Company/Security/ModuleAccessMap.php` | Привязка контроллеров Settlement к существующему FINANCE |
| `site/tests/Architecture/ModuleBoundaryRules.php` | Включить Settlement после создания его Facade |
| `ARCHITECTURE.md` | Только реально реализованные Facade/DTO/Enum/Entity и lock order |

Изменяемые источники Stage 2: `Cash/Facade/CashFacade.php`,
`Finance/Facade/FinanceFacade.php`, `Company/Facade/CompanyFacade.php`, их DTO,
Cash/Finance write paths и новые синхронные domain events. Их полный список
фиксируется в `source-write-paths.md` до подключения источников.

## Общие новые контракты

Имена и поля здесь — предлагаемый контракт реализации. Все новые DTO живут
в `site/src/Settlement/Application/DTO/<Name>.php`, enum — в `Enum/<Name>.php`.

- `SettlementBucket`: RECEIVABLE, PAYABLE, ADVANCE_GIVEN, ADVANCE_RECEIVED,
  UNALLOCATED_OUT, UNALLOCATED_IN; persisted values — lowercase из TASK.md.
- `SettlementDocumentKind`: OPENING, OBLIGATION, PAYMENT, CORRECTION,
  CLASSIFICATION, ALLOCATION_REVERSAL, DOCUMENT_REVERSAL.
- `SettlementDocumentStatus`: DRAFT, POSTED. Отмена — новая запись; исходная
  остаётся проведённым фактом для исторического расчёта.
- `SettlementSourceType`: MANUAL, CASH, FINANCE.
- `CreateSettlementDocumentInput(requestId: string, kind: SettlementDocumentKind,
  counterpartyId: string, bucket: SettlementBucket, amount: Money,
  effectiveDate: DateTimeImmutable, dueDate: ?DateTimeImmutable, reason: string)`.
  Это OPENING/OBLIGATION; payment создаёт только адаптер Cash. source fields
  устанавливает сервер, а не скрытые поля формы.
- `CorrectSettlementItemInput(requestId: string, itemId: string, amount: Money,
  increase: bool, effectiveDate: DateTimeImmutable, reason: string)`.
- `RegisterCashSettlementInput(requestId: string, cashTransactionId: string,
  isAdvance: bool, effectiveDate: DateTimeImmutable)`: сумма/валюта/контрагент и
  исходная дата берутся из заблокированного Cash. Первое включение требует
  effectiveDate=occurredAt; повторное — явной даты не раньше последней отмены
  и occurredAt (A10). Дата не позже today и не в закрытом периоде.
- `CreateSettlementFromFinanceInput(requestId: string, documentId: string,
  bucket: SettlementBucket, amount: Money, effectiveDate: DateTimeImmutable,
  dueDate: ?DateTimeImmutable, reason: string)`: bucket только RECEIVABLE/PAYABLE;
  полная сумма и валюта подтверждаются явно, не выводятся из float total ОПиУ.
- `AllocateSettlementInput(requestId: string, obligationItemId: string,
  paymentItemId: string, amount: Money, effectiveDate: DateTimeImmutable)`.
- `ReverseSettlementInput(requestId: string, effectiveDate: DateTimeImmutable,
  reason: string)`: targetId и actor передаются Action отдельно.
- `SettlementResult(documentId: string, itemId: ?string, version: int, replayed: bool)`.
- `SettlementBalances(receivable: Money, payable: Money, advanceGiven: Money,
  advanceReceived: Money, unallocatedOut: Money, unallocatedIn: Money)`.
- `SettlementReportFilter(from: DateTimeImmutable, to: DateTimeImmutable,
  currency: string, counterpartyId: ?string, page: int, perPage: int)`;
  page >=1, perPage ∈ {25,50,100}; from <= to; даты в Europe/Moscow.
- `SettlementCounterpartyRow(counterpartyId: string, opening: SettlementBalances,
  increases: SettlementBalances, decreases: SettlementBalances,
  closing: SettlementBalances, overdue: Money, withoutDueDate: Money)`.
- `SettlementMovementRow(documentId: string, itemId: string,
  effectiveDate: DateTimeImmutable, bucket: SettlementBucket, delta: Money,
  sourceType: SettlementSourceType, sourceId: ?string, reason: string)`.
- `SettlementReportTotals(opening: SettlementBalances, increases: SettlementBalances,
  decreases: SettlementBalances, closing: SettlementBalances, overdue: Money,
  withoutDueDate: Money)` — без фиктивного контрагента.
- `SettlementReportPage(rows: list<SettlementCounterpartyRow>, total: int,
  totals: SettlementReportTotals)`.
- `SettlementCardPage(rows: list<SettlementMovementRow>, total: int,
  summary: SettlementCounterpartyRow)`.

При сохранении source snapshot хранить только необходимые нормализованные поля,
source ID и fingerprint; без raw payload, банковских реквизитов и секретов.

## Stage 1 — ядро учёта и ручные основания

Risk: HIGH-LOCAL. Definition of Done: после проведения начальных остатков,
обязательств и корректировок получаются точные объяснимые позиции; повтор безопасен.

### Work item 1.1 — схема и финансовые инварианты

**Files:** сущности, enum, Repository, PostingPolicy и миграция из карты;
`site/src/Settlement/Infrastructure/Doctrine/SettlementSchemaExtension.php`;
`site/tests/Builders/Settlement/SettlementDocumentBuilder.php`;
`site/tests/Unit/Settlement/SettlementPostingPolicyTest.php`;
`site/tests/Integration/Settlement/SettlementSchemaTest.php`.

**Interfaces:** `SettlementPostingPolicy::assertAllocation(SettlementItem $obligation,
SettlementItem $payment, Money $amount, DateTimeImmutable $date): void`;
`SettlementRepository::getDocumentForUpdate(string $companyId, string $id): SettlementDocument`;
`getItemForUpdate(string $companyId, string $id): SettlementItem`.

- [ ] Написать red tests: чужие company FK отвергнуты; amount >0; precision 2;
  несовпадающая валюта и отрицательная сумма запрещены; draft не входит в регистр.
  В policy test: `self::assertSame(4000000, Money::fromString('40000.00', 'RUB')->amountMinor());`
  затем `expectException(DomainException::class)` для суммы распределения 40000.01
  при свободных 40000.00.
- [ ] Запустить `docker compose exec -T -e XDEBUG_MODE=off site-php-cli php bin/phpunit tests/Unit/Settlement/SettlementPostingPolicyTest.php`; сохранить red output.
- [ ] Реализовать схему: company-scoped составные FK, unique(requestId,companyId),
  unique sourceType+sourceId+companyId в SettlementSourceLink с nullable activeDocumentId;
  unique companyId+reversedEventType+reversedEventId для единственной прямой отмены;
  unique movement event+item+bucket; индексы
  company/counterparty/currency/date и company/sourceType/sourceId.
  Корректировки/отмены ссылаются на исходные записи, а не перезаписывают их.
  Один документ OPENING/OBLIGATION/PAYMENT создаёт одну позицию; остальные виды
  меняют существующие позиции. Журнал хранит signed delta как `Money` по каждой корзине;
  физическое хранение — minor units и валюта через существующий MoneyAmountType.
- [ ] Дополнить SettlementPostingPolicyTest проверкой: `0.10 RUB + 0.20 RUB = 0.30 RUB`
  через Money; распределение `0.31 RUB` из `0.30 RUB` запрещено, другая валюта запрещена.
  В SettlementSchemaTest проверить persist/hydrate Money для `0.01 RUB` и
  отрицательной дельты `-0.01 RUB`: сохраняются точные minor units и валюта.
- [ ] Зарегистрировать ORM и расширение схемы; применить миграцию только в test DB.
- [ ] Повторить целевые unit/schema tests до PASS; `doctrine:schema:validate --env=test`
  не должен выявлять новых расхождений. Записать pre-existing отдельно.

### Work item 1.2 — проведение, начальные остатки и исправления

**Files:**
`site/src/Settlement/Application/ConfigureSettlementBookAction.php`,
`CreateSettlementDocumentAction.php`, `PostSettlementDocumentAction.php`,
`CorrectSettlementItemAction.php`, `ReverseSettlementDocumentAction.php`;
`site/src/Settlement/Application/Service/SettlementRegisterUpdater.php`;
`site/src/Company/Facade/CompanyFacade.php`;
`site/tests/Integration/Settlement/SettlementDocumentLifecycleTest.php`.

**Interfaces:**
`ConfigureSettlementBookAction::__invoke(string $companyId, string $actorUserId, DateTimeImmutable $startDate): void`;
`CreateSettlementDocumentAction::__invoke(string $companyId, string $actorUserId, CreateSettlementDocumentInput $input): SettlementResult`;
`PostSettlementDocumentAction::__invoke(string $companyId, string $actorUserId, string $documentId, int $expectedVersion): SettlementResult`;
`CorrectSettlementItemAction::__invoke(string $companyId, string $actorUserId, CorrectSettlementItemInput $input): SettlementResult`;
`ReverseSettlementDocumentAction::__invoke(string $companyId, string $actorUserId, string $documentId, ReverseSettlementInput $input): SettlementResult`.
Новый company-scoped `CompanyFacade::getFinanceLockBefore(string $companyId): ?DateTimeImmutable`
возвращает существующую границу без Entity; вызывать после `financialAccess(..., lock: true)`.

- [ ] Написать red tests: 100000.00 обязательства → `payable=10000000` minor;
  коррекция уменьшения 10000.00 → `9000000`; повтор → та же сумма/ID;
  тот же requestId с другой суммой → конфликт; opening не входит в оборот дня;
  отозванные права/чужой companyId/закрытая дата → отказ без записей.
- [ ] Запустить `docker compose exec -T -e XDEBUG_MODE=off site-php-cli php bin/phpunit tests/Integration/Settlement/SettlementDocumentLifecycleTest.php`, зафиксировать red.
- [ ] Реализовать Actions и необходимые DTO. `wrapInTransaction` принадлежит Action;
  Repository/Updater не flush. Проверить актуальные финансовые права внутри транзакции.
  Порядок блокировок: company FOR SHARE → source row (если есть) → SettlementBook
  FOR UPDATE → позиции по сортированным ID. Дату начала после первого проведения
  менять нельзя. Неподдерживаемый kind/bucket и будущая дата отвергаются.
- [ ] Реализовать отмену с датой и причиной: никаких зависимых активных распределений;
  сумма отмены определяется системой. История исходного периода сохраняется.
  Проверять всю последующую историю затронутой позиции, а не только текущий остаток.
  Дата отмены не раньше исходного события и отмен зависимостей. Два разных requestId
  для одной отмены возвращают исходную отмену; сумму/дату исходной отмены не менять.
- [ ] Запустить тест до PASS; обновить checkpoint после Work item.

**Приёмка Stage 1:**
- [ ] Ручное обязательство и четыре вида начальных остатков воспроизводятся из журнала.
- [ ] Черновик не меняет цифры; повтор проведения не дублирует движения.
- [ ] Корректировка имеет основание и не делает открытый остаток отрицательным.
- [ ] Company isolation, права и закрытая дата проверены на уровне Action.
- [ ] Нет float, скрытого взаимозачёта и ручного редактирования итогового долга.

Stage checks: `php bin/phpunit tests/Unit/Settlement` и
`php bin/phpunit tests/Integration/Settlement` через running контейнер;
focused lint/PHPStan затронутых файлов. Commit: `feat: add settlement documents and register`.

## Stage 2 — источники ДДС и ОПиУ

Risk: HIGH-LOCAL. Definition of Done: источник подключается один раз; исправление
через любой путь не оставляет ложный долг; погашение не меняет ОПиУ.

### Work item 2.1 — подключение фактической оплаты

**Files:** `site/src/Cash/Facade/CashFacade.php`;
`site/src/Cash/Application/DTO/CashSettlementSource.php`;
`site/src/Settlement/Application/RegisterCashSettlementAction.php`;
`site/tests/Integration/Settlement/RegisterCashSettlementTest.php`.

**Interfaces:** новый `CashFacade::getSettlementSourceForUpdate(string $companyId,
string $transactionId): CashSettlementSource` (требует активной транзакции, без flush).
DTO: id, companyId, counterpartyId: ?string, amount: Money, occurredAt: DateTimeImmutable,
direction: App\Cash\Enum\Transaction\CashDirection, deleted: bool,
isTransfer: bool, fingerprint: string. `RegisterCashSettlementAction::__invoke(
string $companyId, string $actorUserId, RegisterCashSettlementInput $input): SettlementResult`.

- [ ] Red tests: outflow 40000.00 → `unallocatedOut=4000000`; isAdvance=true →
  `advanceGiven=4000000`; повтор → одна позиция. Нет контрагента, transfer,
  deleted, чужая компания, не RUB, дата до старта → отказ.
  A10: повторное включение после отмены не удваивает старый период;
  effectiveDate раньше отмены отклоняется, Cash остаётся единственной транзакцией.
- [ ] Выполнить `docker compose exec -T -e XDEBUG_MODE=off site-php-cli php bin/phpunit tests/Integration/Settlement/RegisterCashSettlementTest.php`, зафиксировать red.
- [ ] Реализовать DTO/Facade и Action под общей транзакцией/lock order Stage 1.
  Источник перечитывается после блокировки. SettlementSourceLink сериализует одну
  активную связь sourceType+sourceId+companyId; при отмене activeDocumentId очищается,
  lastReversalDate обновляется в той же транзакции. Action создаёт и проводит PAYMENT с позицией
  в одной транзакции. Не менять Cash allocatedAmount и не создавать ОПиУ.
- [ ] Повторить тест до PASS; записать checkpoint.

### Work item 2.2 — обязательство из документа ОПиУ

**Files:** `site/src/Finance/Facade/FinanceFacade.php`;
`site/src/Finance/Application/DTO/FinanceSettlementSource.php`;
`site/src/Settlement/Application/CreateSettlementFromFinanceAction.php`;
Modify: `site/src/Settlement/Application/PostSettlementDocumentAction.php`;
`site/tests/Integration/Settlement/CreateSettlementFromFinanceTest.php`.

**Interfaces:** `FinanceFacade::getSettlementSourceForUpdate(string $companyId,
string $documentId): FinanceSettlementSource`; DTO: id, companyId, counterpartyId,
date, deleted, status, type, operationIds: list<string>, fingerprint.
`CreateSettlementFromFinanceAction::__invoke(string $companyId, string $actorUserId,
CreateSettlementFromFinanceInput $input): SettlementResult`.

- [ ] Red tests A7/A8: полная сумма 120000.00 при ОПиУ 100000.00 создаёт
  `payable=12000000`, исходные operations неизменны; повтор не создаёт второй долг.
  Нет контрагента/чужой источник/удалённый/технический marketplace документ → отказ.
- [ ] Выполнить `docker compose exec -T -e XDEBUG_MODE=off site-php-cli php bin/phpunit tests/Integration/Settlement/CreateSettlementFromFinanceTest.php`, записать red.
- [ ] Реализовать явное создание одного обязательства на один ручной документ.
  При принятии D3: status=ACTIVE, source=null, type ∈ {OTHER, OBLIGATIONS,
  CASHFLOW_EXPENSE}; остальные типы отклоняются. Это явно предлагаемый allowlist
  первой версии, а не классификация всех existing documents. Создание сохраняет
  черновик OBLIGATION; отдельное проведение — Action Stage 1. Поля источника
  повторно проверяются и fingerprint сравнивается перед проведением. Не выводить
  направление из знака суммы или имени категории. Долги по строкам не поддерживать.
  Если источник связан с Cash, lock order: company → Cash → Finance → SettlementBook.
- [ ] Тест до PASS; регрессия `CreateDocumentFromTransactionActionTest` остаётся green.

### Work item 2.3 — защита всех путей изменения источников

**Files:** `docs/tasks/counterparty-settlements/source-write-paths.md`;
`site/src/Cash/Domain/Event/CashSettlementSourceChanging.php`;
`site/src/Finance/Domain/Event/FinanceSettlementSourceChanging.php`;
`site/src/Settlement/EventSubscriber/SettlementSourceMutationSubscriber.php`;
`site/tests/Integration/Settlement/SettlementSourceMutationGuardTest.php`.
Modify: Cash `UpdateCashTransactionAction`, `DeleteCashTransactionAction`,
`BulkDeleteCashTransactionsAction`, `RestoreCashTransactionAction`;
Finance `UpdateManualDocumentAction`, `SoftDeleteDocumentAction`, `RestoreDocumentAction`;
прочие реально найденные write paths из реестра, включая import/commands/DBAL.

**Interfaces:** source-owned readonly event `(companyId: string, sourceId: string,
materialChange: bool)`; subscriber проверяет активные проведённые связи и при
материальном изменении бросает `SettlementSourceInUseException`.
Cash/Finance не зависят от Settlement; subscriber принадлежит Settlement.
Событие синхронное, внутри транзакции после блокировки источника и до изменения.

- [ ] Составить реестр **всех** записывающих путей по rg, включая soft delete,
  изменение суммы/даты/валюты/контрагента/направления/operations и raw SQL.
  У каждого пути: покрыт guard / по scope источник невозможен + проверяемая причина.
- [ ] Red tests: ручная правка, bulk delete и существующий import update связанного
  источника отклоняются атомарно; изменение комментария допустимо; race register
  vs delete не оставляет проведённой оплаты со скрытым источником.
- [ ] Запустить `docker compose exec -T -e XDEBUG_MODE=off site-php-cli php bin/phpunit tests/Integration/Settlement/SettlementSourceMutationGuardTest.php`.
- [ ] Встроить события/блокировки в каждый применимый путь; источник и guard одной
  транзакции. Отказ не должен оставлять persisted изменения других источников bulk.
  Restore сам по себе не восстанавливает отменённые расчётные движения.
- [ ] Повторить тест до PASS. Нельзя закрыть Stage с непроверенными write paths.

**Приёмка Stage 2:**
- [ ] Связать ДДС можно без создания ОПиУ; создать обязательство можно до оплаты.
- [ ] Старые документы и старое распределение allocatedAmount не меняются автоматически.
- [ ] Повтор, bulk, import и конкурентное изменение источника покрыты тестами.
- [ ] В ARCHITECTURE.md записаны реальные новые контракты и порядок блокировок.

Stage checks: integration Settlement + затронутые Cash/Finance tests;
focused PHPStan/CS; review исходящих source write paths. Commit:
`feat: connect settlement sources with guarded lifecycle`.

## Stage 3 — погашения, авансы и отчёты

Risk: HIGH-LOCAL. Definition of Done: A1–A11 проходят; история на дату стабильна;
остатки регистра независимо сверяются с основаниями и распределениями.

### Work item 3.1 — распределение и отмена

**Files:** `site/src/Settlement/Application/AllocateSettlementAction.php`,
`ReverseSettlementAllocationAction.php`, `ClassifySettlementPaymentAction.php`;
`site/tests/Integration/Settlement/SettlementAllocationTest.php`;
`site/tests/Integration/Settlement/SettlementAllocationConcurrencyTest.php`.

**Interfaces:** `AllocateSettlementAction::__invoke(string $companyId, string $actorUserId,
AllocateSettlementInput $input): string` (allocation ID);
`ReverseSettlementAllocationAction::__invoke(string $companyId, string $actorUserId,
string $allocationId, ReverseSettlementInput $input): SettlementResult`;
`ClassifySettlementPaymentAction::__invoke(string $companyId, string $actorUserId,
string $paymentItemId, bool $isAdvance, ReverseSettlementInput $input): SettlementResult`.
Для классификации input несёт requestId/date/reason; исходная оплата неизменна.

- [ ] Red tests A1–A4/A6/A7: после оплаты 40000 из 100000
  `assertSame(6000000, $balances->payable->amountMinor())` и
  `assertSame(0, $balances->unallocatedOut->amountMinor())`;
  повтор возвращает тот же ID. Отмена восстанавливает обе корзины ровно один раз.
  Переклассификация уже частично распределённой оплаты переносит лишь остаток.
  A9: отклонить backdated allocation при отрицательном промежуточном остатке.
  Аналогичные тесты для корректировки, классификации и отмены: сохранять
  неотрицательные позиции на всём затронутом временном интервале.
  A11: две отмены одного target с разными requestId возвращают один reversal ID.
- [ ] Запустить `docker compose exec -T -e XDEBUG_MODE=off site-php-cli php bin/phpunit tests/Integration/Settlement/SettlementAllocationTest.php`, зафиксировать red.
- [ ] Реализовать допустимые пары из TASK.md. В одной транзакции блокировать книгу
  и обе позиции; перечитать свободные суммы. Никакого изменения Cash/Finance.
  Два движения распределения имеют общую event identity. Только явные отмены.
  Для backdated события валидировать последовательность (effectiveDate,eventSequence)
  всех последующих событий обеих позиций. Если хотя бы один остаток отрицателен — rollback.
- [ ] Проверить две параллельные DB-сессии: остаток 40000, два запроса по 30000;
  один успешен, второй отклонён, остаток 10000, единственное распределение.
  Concurrency test не должен проходить только за счёт внешней DAMA-транзакции.
  Отдельно параллельно отменить одно распределение с двумя разными requestId:
  одна reversal-запись, один обратный эффект, ответы с одним ID.
- [ ] Запустить allocation/concurrency tests до PASS; checkpoint.

### Work item 3.2 — сводка, карточка и просрочка

**Files:** ReportQuery и Facade из карты;
`site/src/Company/Facade/CounterpartyFacade.php`,
`site/src/Company/Repository/CounterpartyRepository.php`;
`site/tests/Integration/Settlement/SettlementReportQueryTest.php`.

**Interfaces:** `SettlementFacadeInterface::report(string $companyId,
SettlementReportFilter $filter): SettlementReportPage`;
`card(string $companyId, string $counterpartyId, SettlementReportFilter $filter): SettlementCardPage`.
Read Facade не принимает actor: HTTP слой проверяет FINANCE_READ, companyId обязателен.
Новый `CounterpartyFacade::getLabelsByIds(string $companyId, array $ids): array`
принимает list<string>, возвращает array<string,string> (ID → display label),
включает архивных контрагентов для истории; неизвестные/чужие ID не возвращает.

- [ ] Red tests A5/A6: на 06.11 payable=6000000, на 08.11 payable=10000000,
  unallocatedOut=4000000; на 04.11 payment не существует в отчёте.
  Начальный остаток не включён в обычный оборот; сумма по всей выборке не равна
  сумме одной страницы. При dueDate=05.11 долг просрочен 06.11, но не 05.11.
- [ ] Запустить `docker compose exec -T -e XDEBUG_MODE=off site-php-cli php bin/phpunit tests/Integration/Settlement/SettlementReportQueryTest.php`, сохранить red.
- [ ] Реализовать запросы из согласованного снимка: opening на начало from,
  обороты за [from 00:00; to+1 day 00:00), closing на конец to. Даты Europe/Moscow.
  Шесть корзин отдельно, суммы в RUB, отсутствие срока отдельным показателем.
  Имена контрагентов получать пакетно через новый метод существующего CounterpartyFacade.
- [ ] Дополнить SettlementReportQueryTest: движения `0.10 RUB` и `0.20 RUB`
  дают итог Money `0.30 RUB`; агрегат вне диапазона Money отклоняется до int-cast,
  без усечения или подстановки нуля; запрос не смешивает валюты.
- [ ] Проверить PASS, отсутствие N+1, стабильный порядок date/sequence/id,
  индексы на локальном наборе 10000 движений; сохранить EXPLAIN без пользовательских данных.

### Work item 3.3 — сверка и восстановление регистра

**Files:** `site/src/Settlement/Command/VerifySettlementRegisterCommand.php`,
`RebuildSettlementRegisterCommand.php`;
`site/tests/Integration/Settlement/SettlementRegisterVerificationTest.php`.

**Interfaces:** `app:settlement:verify <companyId>` — только чтение, exit 0 при
совпадении, exit 1 при расхождении; `app:settlement:rebuild <companyId>` — dry-run,
запись только с `--execute`; rebuild не меняет исходные документы/распределения.

- [ ] Red test: намеренно нарушить один delta в test DB → verify exit 1;
  dry-run не изменяет БД; execute восстанавливает из документов → exit 0;
  повтор rebuild не меняет количество и суммы.
- [ ] Запустить `docker compose exec -T -e XDEBUG_MODE=off site-php-cli php bin/phpunit tests/Integration/Settlement/SettlementRegisterVerificationTest.php`.
- [ ] Реализовать независимую сверку по исходным проведённым документам,
  распределениям, классификациям и отменам, а не сравнение двух SUM одного регистра.
  Rebuild использует ту же блокировку книги и атомарно заменяет производные записи.
  Исторические позиции и отмены восстанавливаются, не только текущие totals.
  SettlementSourceLink также восстанавливается; до/после rebuild активная связь
  и lastReversalDate совпадают, одновременно активных включений источника нет.
- [ ] Тест до PASS; production execute не запускать без отдельного одобрения.

**Приёмка Stage 3:**
- [ ] Все A1–A11 проходят; лишнее распределение запрещено.
- [ ] Контрагент может одновременно иметь дебиторку и кредиторку без автозачёта.
- [ ] Отмена сегодня не переписывает вчера; оплата не создаётся повторно.
- [ ] Перестроенный регистр даёт те же отчёты на исторические даты.
- [ ] Сверка обнаруживает пропущенное, дублированное и неверное движение.

Stage checks: весь модуль Settlement, source guards и focused static analysis.
Commit: `feat: add settlement allocation and reconciled reports`.

## Stage 4 — интерфейс

Risk: MEDIUM. Definition of Done: пользователь проходит A1/A2/A5 без SQL и
видит объяснение каждой суммы. Backend-контракты не проектируются заново на фронте.

### Work item 4.1 — формы и отчёты

**Files:**
`site/src/Settlement/Controller/SettlementIndexController.php`,
`SettlementCardController.php`, `SettlementDocumentNewController.php`,
`SettlementDocumentPostController.php`, `SettlementDocumentReverseController.php`,
`SettlementAllocationNewController.php`, `SettlementAllocationReverseController.php`,
`SettlementCorrectionNewController.php`, `SettlementCashSourceController.php`,
`SettlementFinanceSourceController.php`, `SettlementPaymentClassifyController.php`,
`SettlementBookSetupController.php`;
`site/src/Settlement/Form/SettlementDocumentType.php`, `SettlementAllocationType.php`,
`SettlementCorrectionType.php`, `SettlementSourceType.php`, `SettlementBookType.php`;
`site/templates/settlement/index.html.twig`, `card.html.twig`, `document.html.twig`,
`allocation.html.twig`, `correction.html.twig`, `source.html.twig`, `setup.html.twig`;
`site/templates/app/_shell/sidebar.html.twig`, `site/templates/partials/_sidebar.html.twig`;
`site/templates/transaction/show.html.twig`, `site/templates/document/show.html.twig`;
`site/tests/Functional/Settlement/SettlementJourneyTest.php`.

**Interfaces:** GET `/settlements`, `/settlements/counterparties/{id}`;
GET/POST `/settlements/setup`, `/settlements/documents/new`,
`/settlements/allocations/new`, `/settlements/corrections/new`;
POST `/settlements/documents/{id}/post`, `/settlements/documents/{id}/reverse`,
`/settlements/allocations/{id}/reverse`, `/settlements/payments/{id}/classify`;
GET/POST `/settlements/sources/cash/{id}`, `/settlements/sources/finance/{id}`.
Контроллеры вызывают Actions Stage 1–3; сумма/дата не вычисляются в JS.
Каждый контроллер — один `__invoke`; GET/POST одного form scenario остаются одним action.

- [ ] Написать red functional test: создать основание 100000, включить оплату 40000,
  распределить → показать «К оплате 60 000,00» с переходами на оба источника.
  Аванс до основания виден отдельной суммой. Новая компания видит понятный setup.
- [ ] Запустить `docker compose exec -T -e XDEBUG_MODE=off site-php-cli php bin/phpunit tests/Functional/Settlement/SettlementJourneyTest.php`.
- [ ] Реализовать Twig/Symfony Forms по существующей DS; CounterpartyPickerType,
  денежные Twig-фильтры, server-side pagination. Формы содержат requestId/version,
  CSRF; повтор submit безопасен. Ссылки из Cash/Finance ведут в Settlement,
  а не добавляют обратную зависимость на его сервисы.
- [ ] Пройти тест до PASS; ручной smoke desktop/mobile, пустые состояния, ошибки,
  запрет повторной отправки, понятный конфликт старой версии, keyboard/focus.

### Work item 4.2 — права и защитные состояния

**Files:** `site/src/Company/Security/ModuleAccessMap.php`;
`site/tests/Functional/Settlement/SettlementAccessTest.php`;
`site/tests/Functional/Settlement/SettlementValidationTest.php`.

**Interfaces:** только существующие FINANCE_READ/FINANCE_WRITE, без новых grants.

- [ ] Red tests: read-only видит отчёт, POST получает 403; чужой company ID не
  раскрывает документ; подмена sourceId/positionId отклонена; без CSRF запись
  не производится; повтор после смены активной компании не меняет прежнюю компанию.
- [ ] Запустить `docker compose exec -T -e XDEBUG_MODE=off site-php-cli php bin/phpunit tests/Functional/Settlement/SettlementAccessTest.php`.
- [ ] Подключить namespace Settlement к FINANCE; в каждом контроллере получать
  active company до чтения; проверять права мутаций также в Actions.
  Ошибки показывать в принятом проектом формате, без stack trace и raw payload.
- [ ] В SettlementValidationTest проверить: ввод `0.01` сохраняет 1 minor unit;
  ввод `0.001 RUB` отвергнут без записи, а не округлён; сумма вне диапазона отвергнута.
  В SettlementJourneyTest проверить точное отображение `0.30 RUB` и передачу сумм
  формами/ответами строками с валютой; сериализация не преобразует Money в float.
- [ ] Проверить access/validation tests до PASS; frontend checklist из
  stage-report-frontend.md. Для созданных шаблонов выполнить `php bin/console lint:twig templates/settlement`
  через контейнер. При изменении assets запустить существующие npm lint/build/test:unit
  в `site-frontend`; api:types:check только при изменении OpenAPI.

**Приёмка Stage 4:**
- [ ] Видны четыре вида долга/аванса и отдельные нераспределённые оплаты.
- [ ] Есть фильтр периода, контрагента, карточка движений, исходные документы.
- [ ] Пользователь различает «не участвует», «не распределено», «аванс», «погашено».
- [ ] Формы и отчёты доступны с клавиатуры и на узком экране.
- [ ] Нельзя провести без права, компании, корректной суммы, даты и CSRF.

Stage checks: functional Settlement + Twig lint + применимые frontend gates.
Commit: `feat: expose counterparty settlements and payment allocation`.

## Stage 5 — итоговая приёмка и handoff

Risk: HIGH-LOCAL. Definition of Done: матрица ниже подтверждена тестами/протоколом,
нет открытых BLOCKER/IMPORTANT; миграционный выпуск подготовлен к решению владельца.

### Work item 5.1 — сквозная приёмка

**Files:** `site/tests/Integration/Settlement/SettlementAcceptanceTest.php`;
`docs/tasks/counterparty-settlements/acceptance.md`, `handoff.md`, `checkpoint.md`.

- [ ] Создать изолированный acceptance dataset для A1–A11; даты задавать явно,
  не привязывать сценарий к реальному today; startDate=2026-11-01.
- [ ] Добавить тесты matrix P01–P21, если ещё не покрыты owning tests. Не дублировать
  реализацию в assertions: сравнивать наблюдаемые суммы, строки, HTTP и число записей.
- [ ] Выполнить `docker compose exec -T -e XDEBUG_MODE=off site-php-cli php bin/phpunit tests/Integration/Settlement/SettlementAcceptanceTest.php`.
- [ ] Заполнить acceptance.md: ID, фактический результат, команда/тест, commit.
  Чекбокс становится [x] только после фактического PASS; сейчас все остаются [ ].

### Work item 5.2 — полные gates, review, релизная готовность

- [ ] Один раз на handoff выполнить `make site-stan`, `make site-cs-check`,
  `make site-cs-strict-types`, `make site-test-unit`, `make site-test`.
  Для изменённых assets — действующие frontend gates. Записать реальные outputs.
- [ ] Проверить миграцию на пустой test DB и на копии локальной тестовой схемы
  с существующими документами: их количества/суммы не изменились, связи не создались.
- [ ] Final internal review в свежей сессии: только TASK.md, полный task diff,
  backend/frontend checklist. Исправить BLOCKER/IMPORTANT и безопасные MINOR.
- [ ] Прочитать external-review.md и выполнить обязательный read-only review
  `site/bin/external-review.sh <task_base_commit> --effort medium`; следовать
  marker/fix policy и лимиту трёх rounds. В текущем документационном PR не запускать.
- [ ] Обновить ARCHITECTURE.md, Stage Reports, checkpoint, handoff; проверить PR base
  `master`, подготовить rollback: возврат кода без удаления финансовых записей,
  additive tables сохраняются. Фактическое имя миграции включить в handoff.
- [ ] Mark Ready; запросить одобрение production migration + deploy по release.md.
  До одобрения не merge и не запускать production commands.

Commit: `test: verify settlement acceptance and release readiness`.

## Сквозной чек-лист приёмки

| Done | ID | Проверка | Ожидаемый результат | Где доказать |
|---|---|---|---|---|
| [ ] | P01 | Начальный долг 25000, оборотов нет | Opening=closing=25000, обороты=0 | Lifecycle + ReportQuery |
| [ ] | P02 | A1: 100000 − 40000 | Долг 60000; нераспределено 0 | Allocation |
| [ ] | P03 | A2: аванс 40000 → акт 100000 → доплата 60000 | Аванс 0, долг 0 | Allocation + Journey |
| [ ] | P04 | A3: покупатель 120000 − 30000 − 50000 | Дебиторка 40000 | Allocation |
| [ ] | P05 | A4: один платёж на два документа | Остаток второго 10000, перерасход запрещён | Allocation |
| [ ] | P06 | A5: отмена распределения 07.11 | История 06.11 не изменилась; деньги не вернулись | ReportQuery |
| [ ] | P07 | A6: встречные долги 30000 и 50000 | Две отдельные суммы без зачёта | ReportQuery |
| [ ] | P08 | A7: оплата основания из ОПиУ | ОПиУ не изменён, нового расхода/дохода нет | FinanceSource + Allocation |
| [ ] | P09 | A8: полная сумма отличается от ОПиУ | Долг 120000, ОПиУ 100000 | FinanceSource |
| [ ] | P10 | Неидентифицированный платёж | Отдельная корзина, не автоматический аванс | CashSource + ReportQuery |
| [ ] | P11 | Повтор запроса / конфликт payload | Тот же ID / отказ без новых движений | Lifecycle + Allocation |
| [ ] | P12 | Два распределения по 30000 из 40000 | Одно успешно, остаток 10000 | Concurrency |
| [ ] | P13 | Чужая компания / read-only / отозванное право | Отказ, чужие данные не раскрыты | Access + Lifecycle |
| [ ] | P14 | Изменение/удаление источника через bulk/import | Атомарный отказ при активной связи | SourceMutationGuard |
| [ ] | P15 | Закрытая дата / будущая дата / до старта | Проведение запрещено | Lifecycle + Validation |
| [ ] | P16 | Срок 05.11; отчёт 05.11/06.11; нет срока | 0/положительная просрочка; без срока отдельно | ReportQuery |
| [ ] | P17 | Порча производного регистра + rebuild | Verify обнаружил; rebuild вернул все historical totals | Verification |
| [ ] | P18 | Миграция при существующих Cash/Finance | Исходные данные неизменны, старые связи не мигрированы | Schema + acceptance protocol |
| [ ] | P19 | A9: распределение/коррекция/классификация/отмена задним числом | Ни один промежуточный остаток не отрицателен; иначе rollback | Lifecycle + Allocation + ReportQuery |
| [ ] | P20 | A10: повторное включение Cash после отмены | На 06/07/08.11 учтено 40000/0/40000, один Cash | CashSource + ReportQuery |
| [ ] | P21 | A11: две отмены с разными requestId | Один reversal ID и один эффект, в том числе конкурентно | Lifecycle + Concurrency |
| [ ] | P22 | Money во всех денежных контрактах и новых Entity; 0.10 + 0.20; распределение 0.31 из 0.30 | Единственный общий Money; итог 0.30; перерасход и смешение валют запрещены | PostingPolicy + Schema + review контрактов |
| [ ] | P23 | Формы/сериализация 0.01 и 0.30; ввод 0.001 RUB и суммы вне диапазона | Точные строки с валютой; невалидный ввод отклонён без записи и скрытого округления | Validation + Journey |
| [ ] | P24 | SQL SUM 0.10 + 0.20; агрегат вне диапазона | Money 0.30; проверка диапазона до int-cast, без float/усечения | ReportQuery |

## Контроль готовности самого плана

- [x] Сверены существующие пути и команды с репозиторием.
- [x] Каждый результат TASK.md связан с Work item и приёмочным сценарием.
- [x] Названия DTO, поля и сигнатуры согласованы между этапами внутри плана.
- [x] Пять рисков Review Focus имеют owning tests.
- [x] D1–D7 явно отличены от уже согласованных требований.
- [x] Финансовые примеры не требуют автоматического неттинга, FX или вывода НДС.
- [x] Не заявлены выполненными будущие тесты, review, миграция и релиз.

Для исполнения рекомендуется последовательная реализация одним агентом:
Stage тесно связаны общими инвариантами и транзакциями; независимый свежий review
на handoff обязателен. Параллельную реализацию источников можно рассмотреть только
после фиксации Stage 1 contracts. Способ исполнения и запуск — отдельное решение
после рассмотрения плана; этот документ не начинает реализацию.
