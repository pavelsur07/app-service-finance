# Модуль `Cash`: паттерны и структура — вторая волна

Дата: 2026-11-11. Продолжение `docs/tasks/cash-architecture/plan.md` (этапы 0–8
закрыты 15.10.2026). Там закрыты транзакции, IDOR, границы модуля, Money VO,
гейты. Этот план — то, что осталось после первой волны и #2964
(cash-controller-remediation).

Аудит статический: grep по `site/src/Cash` и подсчёт записей
`site/phpstan-baseline.neon` на дату документа. Тесты и PHPStan не запускались.

## Снимок модуля

- 368 файлов, 33,6 тыс. строк, 20 каталогов верхнего уровня; ~190 тестовых файлов.
- Крупнейшие: `ClientBank1CImportService` 1050, `CashTransaction` 814,
  `CashTransactionAutoRuleService` 807, `CashTransactionRepository` 796,
  `CashFileImportService` 711, `CashFacade` 672 (39 публичных методов).
- Baseline Cash — 138 записей: `missingType.iterableValue` 46,
  `missingType.generics` 25, `argument.type` 18, `doctrine.columnType` 14.
  По файлам: `ClientBank1CImportService` 22 записи (30 ошибок),
  `CashFileImportJob` 9, `MoneyAccount` 9, `CashTransaction` 8,
  `CashTransactionRepository` 7, `CashTransactionAutoRuleService` 6.

**В порядке, не трогаем:** `flush()`/`wrapInTransaction()` только в Action,
Handler, Command; из чужих модулей импортируются только `Application/DTO`;
компания в методах репозиториев. Эталон сущности — `CashTransfer`,
`CashTransactionSplit`, `CashCategorySuggestion`: без сеттеров,
`string $companyId`, ключ повтора задаёт вызывающий.

## Общие правила

Действуют правила `cash-architecture/plan.md` («Общие правила», «Попутные
правила», «Не делаем»). Дополнительно:

- Этапы независимы, кроме 4 → 1 (DTO строки 1С удобнее делать после единого
  хеша). Порядок ниже — по соотношению пользы и риска.
- Поведение для пользователя не меняется. Миграций нет; понадобилась — STOP.
- Одна задача — одна ветка и один PR (`AGENTS.md` §8), этап — отдельный
  коммит; baseline только сокращается. Этапы 2–3: ветка
  `refactor/cash-structure-stages-2-3`.

---

## Этап 1. Единый dedupe-хеш и сумма 1С без `float`

**Задача.** `normalizePurposeForDedupe()` и `makeDedupeHash()` побайтно
скопированы в `Service/Import/ClientBank1CImportService.php:791-811` и
`Service/Import/File/CashFileImportService.php:595-619`;
`getOrCreateCounterparty` — тоже в двух вариантах. Хеш ловит дубли между
импортом 1С и файлом: правка одной копии молча ломает межисточниковую
дедупликацию. Сумма 1С идёт через `float`: `parseAmount(): float` (`:987`),
`number_format((float) …)` в `resolveAmount` (`:715`) и `formatAmountForHash`
(`:961`). `MoneyFloatCastRule` это не видит (ловит только
`(float) get*Amount()`). Находка первой волны («Находки»,
`number_format((float) …)`) — закрывается здесь.

**Результат.** Один класс хеша в `Service/Import/Identity/` рядом с
`CashTransactionExternalIdGenerator`; оба импорта зовут его. Сумма 1С
разбирается строкой (`Money` / bcmath) до минорных единиц.

**Что выполнено.** —

**Чек-лист приёмки:**

- [ ] Нет второй реализации `makeDedupeHash`/`normalizePurposeForDedupe` в `src/`.
- [ ] Тест: одна операция из 1С и из файла → одинаковый `dedupe_hash`.
- [ ] Тест суммы 1С: `"1 234,56"`, `"0.10"`, большие суммы → точные минорные единицы.
- [ ] Хеш для существующих данных не меняется (тест на фиксированном векторе).
- [ ] Записи baseline затронутых методов закрыты, гейты зелёные.

## Этап 2. Правило размещения и перенос мелких каталогов

**Решение Владельца перед стартом.** Отдельный PR противоречит попутному правилу
первой волны («отдельных PR под них не делать»). За месяц попутно не сдвинулся
ни один из 12 файлов. Без согласия этап не начинать.

### Задача

В модуле две раскладки, куда класть новый класс — неясно:

| Старое место | Файлов | Целевое место | Уже там |
|---|---|---|---|
| `src/Cash/Query/` | 1 | `src/Cash/Infrastructure/Query/` | 8 |
| `src/Cash/DTO/` (+ `DTO/Template/`) | 9 | `src/Cash/Application/DTO/` | 41 |
| `src/Cash/EventSubscriber/{Audit,Transaction}/` | 2 | `src/Cash/EventListener/` | 5 |

Попутная находка: оба класса в `EventSubscriber/` одновременно реализуют
`Doctrine\Common\EventSubscriber` (с `getSubscribedEvents()`) и помечены
`#[AsDoctrineListener]`. DoctrineBundle 3.3.2 тег `doctrine.event_subscriber`
не поддерживает — регистрирует только атрибут, интерфейс мёртвый. Название
«Subscriber» вводит в заблуждение, отсюда и отдельный каталог.

### Результат

1. Перенос (`git mv`, только namespace и `use`, логика не меняется):

   | Откуда | Куда | Потребители (src / tests) |
   |---|---|---|
   | `Query/PaymentCalendarQuery` | `Infrastructure/Query/PaymentCalendarQuery` | `Http/PaymentCalendarPage` / `Integration/Cash/Query/PaymentCalendarQueryTest` |
   | `DTO/CashTransactionDTO` | `Application/DTO/CashTransactionDTO` | 7 / 9 (в т. ч. `tests/Fixtures/Cash/mutate-split-source.php`, `Integration/Settlement/SettlementSourceMutationGuardTest`) |
   | `DTO/PaymentPlanDTO` | `Application/DTO/PaymentPlanDTO` | 6 / 2 |
   | `DTO/CashTransactionFilters` | `Application/DTO/CashTransactionFilters` | 2 / 1 |
   | `DTO/CashTransferFormData` | `Application/DTO/CashTransferFormData` | 2 / 1 |
   | `DTO/CashflowCategoryUsage` | `Application/DTO/CashflowCategoryUsage` | 2 / 1 |
   | `DTO/ForecastDTO` | `Application/DTO/ForecastDTO` | 2 / 0 |
   | `DTO/DailyBalancesDTO` | `Application/DTO/DailyBalancesDTO` | 1 / 0 (ссылается на `MoneyBalanceDTO` без `use` — переносить вместе) |
   | `DTO/MoneyBalanceDTO` | `Application/DTO/MoneyBalanceDTO` | 1 / 0 |
   | `DTO/Template/CashflowCoreTemplateNode` | `Application/DTO/Template/CashflowCoreTemplateNode` | 2 / 1 |
   | `EventSubscriber/Audit/AuditLogSubscriber` | `EventListener/AuditLogListener` | 0 / 1 |
   | `EventSubscriber/Transaction/CashTransactionAutoRulesSubscriber` | `EventListener/CashTransactionAutoRulesListener` | 0 / 1 |

   Коллизий имён в `Application/DTO/` и `Infrastructure/Query/` нет (проверено).
   Все DTO — в `Application/DTO/` без разделения на «формы» и «команды»: одно
   правило проще двух; `Application/DTO` и так разрешён другим модулям как
   контракт Facade, а внешних потребителей у переносимых DTO нет.

2. У двух Doctrine-листенеров удалить `implements EventSubscriber` и
   `getSubscribedEvents()` — после проверки, что регистрация не меняется
   (чек-лист). Это единственная правка кода сверх переноса.

3. Тесты — за классами: `tests/Integration/Cash/Query/PaymentCalendarQueryTest`
   → `tests/Integration/Cash/Infrastructure/`, `tests/Unit/Cash/EventSubscriber/**`
   → `tests/Unit/Cash/EventListener/` с переименованием под новые имена.

4. Документы:
   - `ARCHITECTURE.md:2394` — `Cash\EventSubscriber\Audit\AuditLogSubscriber` →
     `Cash\EventListener\AuditLogListener`; там же раздел Cash — правило
     размещения (ниже).
   - `site/src/Cash/README.md:58` — строка `EventListener`, `EventSubscriber` → один
     `EventListener`.
   - `docs/tasks/cash-architecture/plan.md`, «Попутные правила» — пометить, что
     `DTO/` закрыт этим этапом.

   Правило размещения для `ARCHITECTURE.md`, раздел Cash:

   | Что | Куда |
   |---|---|
   | DTO, команды, результаты | `Application/DTO/` |
   | Read-запросы, отчёты, проекции | `Infrastructure/Query/` |
   | Слушатели событий Symfony и Doctrine | `EventListener/` |
   | Новый stateless-сервис | `Application/Service/` |
   | `Service/` | новое не класть; переносить при касании |

**Не лезем:** `Service/` (30 файлов), `Message/` → `Application/Message/`,
`Http/`, переименование самих DTO (`*DTO` суффикс остаётся), любая логика.

### Шаги

1. `git switch -c refactor/cash-layout-dirs` от свежего `master`.
2. Снимок «до»: `bin/console debug:container --tag=doctrine.event_listener` —
   строки `AuditLogSubscriber` и `CashTransactionAutoRulesSubscriber` с
   событиями (`postPersist`, `postUpdate`), записать в отчёт этапа.
3. `git mv` по таблице, затем замена namespace/`use` по `src/`, `tests/`,
   `config/` (поиск по FQCN, включая `tests/Fixtures/Cash/*.php`).
4. Переименование листенеров и удаление мёртвого интерфейса.
5. Снимок «после» той же командой — те же события, по одному тегу на событие.
6. Документы (п. 4 результата).
7. Гейты по одному (OOM на dev-машине): `make site-cs-check`,
   `make site-cs-strict-types`, `make site-stan`, baseline-guard в контейнере,
   `make site-test-unit`, затем интеграционные/функциональные тесты Cash и
   `Settlement`.

### Что выполнено

—

### Чек-лист приёмки

- [ ] Каталогов `src/Cash/Query/`, `src/Cash/DTO/`, `src/Cash/EventSubscriber/` нет.
- [ ] `grep -rF 'App\Cash\DTO\' src tests config` и то же для `App\Cash\Query\`,
      `App\Cash\EventSubscriber\` — 0 строк.
- [ ] `git diff -M --stat master` — 12 переименований в `src/`, тесты за ними;
      `git diff -M` по перенесённым файлам — только `namespace`/`use` (и у двух
      листенеров — удалённые интерфейс, `getSubscribedEvents()`, новое имя).
- [ ] `debug:container --tag=doctrine.event_listener` до и после: те же события
      `postPersist`/`postUpdate` у обоих листенеров, ровно по одному тегу на
      событие; новые имена классов.
- [ ] Тесты листенеров (`AuditLogSubscriberTest`,
      `CashTransactionAutoRulesSubscriberTest` под новыми именами) зелёные;
      аудит при создании транзакции и постановка автоправил проверены
      интеграционными тестами Cash.
- [ ] `PaymentCalendarQueryTest`, `SettlementSourceMutationGuardTest`,
      `CashflowCoreTemplateReaderTest`, `CashTransferTypeTest` зелёные.
- [ ] Baseline-guard зелёный без `[baseline-grow]`; три записи
      `EventSubscriber/*` исчезли (ошибки починены, baseline Cash 138 → 135).
- [ ] `make site-stan`, `site-cs-check`, `site-cs-strict-types`, полный
      `make site-test` зелёные.
- [ ] Правило размещения записано в раздел Cash `ARCHITECTURE.md`; ссылка
      `ARCHITECTURE.md:2394` и `README.md:58` обновлены.
- [ ] Перед handoff `gh pr view --json mergeable` = `MERGEABLE` (переносы
      конфликтуют с параллельными PR; `master` вливать только при конфликте).

## Этап 3. Чистка `CashTransactionRepository`

### Задача

`site/src/Cash/Repository/Transaction/CashTransactionRepository.php` — 796 строк,
28 публичных методов, 7 записей baseline. Вызовы каждого метода проверены по
`src/` и `tests/`:

| Метод | Вызывающие | Проблема |
|---|---|---|
| `sumCapexOutflowByDayExcludeTransfers` | нет | мёртвый, вдобавок `abs((float) …)` по сумме |
| `findIdByCompanyImportSourceExternalIdDbal` | только `CashTransactionRepositoryTest` | мёртвый в коде |
| `findOneByImport` | `AddCashTransactionAction:73` | алиас `findOneByCompanyImportSourceExternalId`, у которого других вызовов нет |
| `sumByAccountAndPeriod` | `CashFacade::sumTurnoversByAccount` | отчётный агрегат в репозитории |
| `sumGrossTurnoverByPeriodExcludeTransfers` | `CashFacade::sumGrossTurnoverExcludingTransfers`; `CashReportQuery:211` ссылается на него в комментарии как на эталон охвата | отчётный агрегат в репозитории, охват дублируется с `CashReportQuery::listCashflowReportRows` |

Остальные варианты поиска по внешнему ключу нужны и остаются:
`findOneByCompanyImportSourceExternalId` (ORM, живые — ручное добавление),
`findAnyByCompanyImportSourceExternalId` (ORM, включая удалённые — повтор
команды в `CashFacade`), `findAnyIdByCompanyImportSourceExternalIdDbal` (DBAL,
после `UniqueConstraintViolation`, когда EntityManager закрыт),
`findImportedExternalIds` (пачкой), `findActiveByCompanyAccountExternalId`
(по счёту, импорт 1С).

### Результат

1. Удалены `sumCapexOutflowByDayExcludeTransfers`,
   `findIdByCompanyImportSourceExternalIdDbal` (и
   `testFindIdByCompanyImportSourceExternalIdDbalReturnsTransactionId`).
2. Удалён алиас `findOneByImport`; `AddCashTransactionAction` вызывает
   `findOneByCompanyImportSourceExternalId` напрямую.
3. `sumByAccountAndPeriod` и `sumGrossTurnoverByPeriodExcludeTransfers`
   перенесены в `Infrastructure/Query/CashReportQuery` как
   `sumTurnoversByAccount(string $companyId, …)` и
   `sumGrossTurnoverExcludingTransfers(string $companyId, …)`:
   - DQL без изменения условий (сравнивать построчно), параметр компании —
     `string` + `Assert::uuid()`, как у остальных методов `CashReportQuery`;
   - возвращаемые формы те же (`array<string, array{inflow, outflow}>` и
     `array{inflow, outflow}` decimal-строками) — контракт `CashFacade` не
     меняется, поэтому новые DTO не вводятся;
   - `CashFacade` по-прежнему вызывает `requireCompany()` перед запросом: для
     несуществующей компании остаётся `\DomainException`, поведение то же;
   - комментарий `CashReportQuery:211` ссылается на соседний метод того же класса.
4. `ARCHITECTURE.md`, раздел `CashFacade`: у `sumTurnoversByAccount` и
   `sumGrossTurnoverExcludingTransfers` источник — `CashReportQuery`.

Итог: 23 публичных метода вместо 28, минус 165 строк из репозитория.

**Не лезем:**

- Перевод остальных методов с `Company` на `string $companyId`
  (`sumByDay`, `paginateByCompanyWithFilters`, `iterateByCompanyWithFilters`,
  `maxUpdatedAtForCompany`) — при касании.
- `maxUpdatedAtForCompany` всегда возвращает `null` (`MAX()` приходит строкой,
  проверка `instanceof \DateTimeInterface` не проходит; находка первой волны,
  этап 7.1). Исправление меняет видимое поведение дашборда — отдельная задача
  с регрессионным тестом, по решению Владельца.
- `sumByDay` (вызывают `AccountBalanceService`, `ForecastBalanceService` внутри
  Cash) — не отчёт для других модулей, остаётся.

### Шаги

1. Ветка `refactor/cash-repository-cleanup` от свежего `master` (независима от
   этапа 2; если этап 2 уже влит — от него).
2. Перед удалением повторно проверить отсутствие вызовов:
   `grep -rnE '(->|::)(sumCapexOutflowByDayExcludeTransfers|findIdByCompanyImportSourceExternalIdDbal|findOneByImport)\(' src tests`.
3. Удаления (п. 1–2 результата).
4. Перенос агрегатов: сначала тесты на `CashReportQuery` (ожидания из
   `CashFacadeReportReadTest` — `CashFacadeDashboardReadTest` эти два метода не
   покрывает; добавить изоляцию компании), затем перенос кода, затем `CashFacade`.
5. Записи baseline удалённых и перенесённых методов — удалить или перенести.
6. Гейты по одному, как в этапе 2.

### Что выполнено

2026-11-11, ветка `refactor/cash-structure-stages-2-3`.

- Удалены `sumCapexOutflowByDayExcludeTransfers`, `findIdByCompanyImportSourceExternalIdDbal`
  (с тестом и записью baseline), алиас `findOneByImport`.
- `sumByAccountAndPeriod`, `sumGrossTurnoverByPeriodExcludeTransfers` перенесены в
  `CashReportQuery::sumTurnoversByAccount` / `sumGrossTurnoverExcludingTransfers`;
  DQL сверен построчно — отличие только `from()` и строковый параметр компании.
  `CashFacade` по-прежнему вызывает `requireCompany()`.
- Тест `CashFacadeReportReadTest::testGrossTurnoverIgnoresForeignCompanyTechnicalOtherCurrencyAndDeleted`
  (чужая компания, техническая статья, «нераспределённое» с `flowKind`, другая
  валюта, удалённые, вне периода) — зелёный на старом и новом коде.
- Репозиторий: 796 → 631 строк, 28 → 23 публичных метода. Baseline −1 запись.
- Проверки: тесты Cash/Settlement/Report/Analytics — 1132 OK (до — 1115 OK
  без Analytics); PHPStan полный — единственное расхождение (лишняя запись
  baseline) устранено; CS по изменённым файлам — 0.

### Чек-лист приёмки

- [x] Шаг 2 дал вызовы только в самом репозитории и в удаляемом тесте.
- [x] В `CashTransactionRepository` нет `sumCapexOutflowByDayExcludeTransfers`,
      `findIdByCompanyImportSourceExternalIdDbal`, `findOneByImport`,
      `sumByAccountAndPeriod`, `sumGrossTurnoverByPeriodExcludeTransfers`;
      публичных методов 23.
- [x] DQL перенесённых методов совпадает с исходным построчно (условия,
      join'ы, параметры, `bcadd(…, '0', 2)`), отличие — только параметр компании.
- [x] Тесты на оба метода (через `CashFacade` — контракт; `CashFacadeReportReadTest`): суммы, исключение переводов и
      технических статей, валюта, `flowKind` с «нераспределённым», изоляция
      компании (данные чужой компании в сумму не входят).
- [x] `CashFacadeReportReadTest`, `CashFacadeDashboardReadTest`,
      `CashflowReportBuilderTest`, `CashTransactionActionsTest`,
      `CreationRepeatTest`, `CashTransactionRepositoryTest` зелёные **без
      изменения ожиданий**.
- [x] Повтор ручного добавления с тем же `(importSource, externalId)` даёт ту же
      транзакцию и то же число строк (существующий тест идемпотентности зелёный).
- [x] Сигнатуры публичных методов `CashFacade` не изменились
      (`git diff master -- site/src/Cash/Facade/CashFacade.php` — только тела).
- [x] Записи baseline Cash не выросли; записи удалённых методов исчезли
      (было 138 записей — зафиксировать «после» в журнале).
- [x] `RepositoryCompanyScopeRule` зелёный; новые методы `CashReportQuery`
      принимают `string $companyId`.
- [ ] `make site-stan`, `site-cs-check`, `site-cs-strict-types`, полный
      `make site-test` зелёные.
- [x] `ARCHITECTURE.md` (раздел `CashFacade`) обновлён.

## Что учесть при выполнении этапов 2–3

Проверено 2026-11-11 по коду, конфигам и открытым PR.

**Оба этапа**

- **Делать последовательно, не параллельно.** `AddCashTransactionAction` и
  `CashFacade` правятся в обоих (импорт `CashTransactionDTO` в этапе 2,
  `findOneByImport` и агрегаты в этапе 3) — конфликт в блоке `use`. Порядок
  3 → 2: этап 3 не ждёт решения Владельца.
- **Окно для работы свободно.** Открытых PR по затронутым файлам нет (#2908 и
  #2493 — только docs). Но Cash активно менялся 06–10.11 (#2964 и др.) — ветку
  от свежего `master`, перед handoff `gh pr view --json mergeable`.
- **Коммит только с pathspec, `git add` по файлам.** В рабочем дереве лежат
  untracked zip-паки и `docs/integrations/` Владельца.
- **Push и PR одним шагом на handoff**, этапы — локальными коммитами;
  отчёт по `docs/workflow/stage-report.md`, внешнее ревью Codex
  (`docs/workflow/external-review.md`).
- **Миграций и данных нет** — отдельный релиз с миграцией не нужен; после
  деплоя достаточно открыть дашборд, отчёт ДДС, платёжный календарь и создать
  операцию (аудит и автоправила).

**Этап 2**

- **Baseline сломает PHPStan, а не guard.** `reportUnmatchedIgnoredErrors: true`,
  а сообщения трёх записей `EventSubscriber/*` содержат FQCN класса
  (`App\Cash\EventSubscriber\…\::postPersist() has parameter $args with generic
  class LifecycleEventArgs…`, `method_exists() … always true`). После
  переименования записи станут «unmatched». Не переписывать FQCN в baseline, а
  починить: `PostPersistEventArgs` вместо `LifecycleEventArgs`, убрать
  `method_exists`. Baseline −3. `make site-stan-baseline` не запускать.
- **Порядок Doctrine-листенеров не меняется.** Других листенеров
  `postPersist`/`postUpdate` в проекте нет (Shared — `postGenerateSchema`,
  Marketplace — flush-события), относительный порядок Audit → AutoRules
  сохраняется по алфавиту. Всё равно сверить в снимке `debug:container`.
- **Тесты не зовут `getSubscribedEvents()`** — удаление интерфейса их не ломает.
- **Сериализованных копий DTO нет.** Сессия импорта (`cash_file_import`)
  хранит массивы, не объекты; Messenger-сообщений среди переносимых классов нет —
  старые FQCN в Redis/очередях не застрянут.
- **DI не затронут.** `App\: resource: '../src/'` без `exclude` — DTO и сейчас
  попадают в сканирование и вычищаются как неиспользуемые; перенос это не меняет.
- **Строковые FQCN:** в `config/`, `templates/`, YAML и Twig `constant()`
  ссылок на `App\Cash\DTO|Query|EventSubscriber` нет; но грепать и
  `tests/Fixtures/Cash/*.php` — это отдельные скрипты, которые запускаются
  вне автозагрузки тестов.

**Этап 3**

- **`RepositoryCompanyScopeRule` Query-классы не проверяет.** После переноса
  агрегатов в `CashReportQuery` фильтр по компании — только ручное ревью:
  `t.company = :company` в каждом запросе, плюс тест изоляции компании.
- **Параметр компании строкой работает.** `CashReportQuery` уже сравнивает
  `t.company = :company` с `string $companyId` (`listCashflowReportRows`) —
  тот же приём, без `IDENTITY()`.
- **Покрытие есть.** Оба переносимых метода покрыты интеграционно в
  `CashFacadeReportReadTest:90,111-112` (включая `flowKind`);
  `CashflowReportBuilderTest` мокает Facade и переносу не помеха. Тесты гонять
  до переноса — они фиксируют «до».
- **`findOneByImport` против `findAny…`** — разная семантика намеренно:
  ручное добавление ищет только живые, повтор команды Facade — включая
  удалённые (уникальный индекс `uniq_cashflow_import` держит удалённые).
  Не «объединять» их при чистке.
- **DBAL-вариант `findAnyId…Dbal` не трогать** — он нужен после
  `UniqueConstraintViolation`, когда EntityManager закрыт.

## Этап 4. DTO строки выписки 1С и разделение импорта

**Задача.** `ClientBank1CImportService` (1050 строк) передаёт `array $preview`
и `array $row` через разбор, предпросмотр и запись — отсюда 22 записи baseline
и нечитаемость.

**Результат.** `final readonly` DTO строки; три класса: разбор файла,
построение предпросмотра, запись (без `flush`, коммит — у Action).

**Что выполнено.** —

**Чек-лист приёмки:**

- [ ] Записи baseline `ClientBank1CImportService` закрыты (было 22 записи, 30 ошибок).
- [ ] Существующие интеграционные тесты импорта 1С зелёные без правки ожиданий.
- [ ] Повторный импорт той же выписки — то же число строк (тест есть или добавлен).

## Этап 5. Узкие интерфейсы `CashFacade`

**Задача.** 39 методов на несвязанных потребителей, интерфейса нет: BankSync —
11 (включая служебные `tryLock…`, `release…Lock`,
`start/finish/abandonStatementImportLog`), дашборд Analytics — ~15 `sum*`,
отчёт ДДС (Cashflow) — 4, переводы — 3.

**Результат.** `CashStatementImportFacadeInterface`,
`CashTurnoverFacadeInterface`, `CashflowReportFacadeInterface` (имена — при
реализации); реализует текущий `CashFacade`; потребители зависят от узкого
интерфейса. `ARCHITECTURE.md` обновлён.

**Не лезем:** объединение служебных методов блокировки и журнала в один вызов —
Handler BankSync сам владеет фазами транзакций; отдельная задача.

**Что выполнено.** —

**Чек-лист приёмки:**

- [ ] Ни один потребитель вне Cash не зависит от класса `CashFacade` напрямую.
- [ ] Сигнатуры методов не менялись.
- [ ] Раздел `CashFacade` в `ARCHITECTURE.md` разбит по интерфейсам.

## Этап 6. Инварианты `CashTransaction` в сущности

**Задача.** У `CashTransaction` 20 сеттеров (`setAmount`, `setCurrency`,
`setDirection`, `setMoneyAccount`, `setVatAmount`…), у `MoneyAccount` 25, у
`PaymentPlan` 16. Инварианты (счёт той же компании, НДС согласован с суммой,
сплиты после смены суммы) — в `UpdateCashTransactionAction`,
`CashTransactionService`, импортах. Противоречит `CLAUDE.md` («Entity защищает
свои инварианты»).

**Результат.** По образцу `CashTransfer` — методы смысла:
`changeMoney(amount, direction, currency, vat)`, `moveToAccount()`,
`reclassify(category, project, cfo)`. Денежные сеттеры удалены. Подэтапы:
6.1 деньги, 6.2 счёт, 6.3 классификация; `MoneyAccount`, `PaymentPlan` —
отдельной задачей.

**Что выполнено.** —

**Чек-лист приёмки:**

- [ ] Нет `setAmount`/`setCurrency`/`setDirection`/`setVat*` у `CashTransaction`.
- [ ] Unit-тесты на каждую ветку инварианта (красные при нарушении).
- [ ] Регистр ОПиУ и остатки после правки суммы — тест без изменения ожиданий.

## Мелочи — попутно, без отдельного PR

- `Controller/Import/CashFileImportResultController.php` —
  `entityManager->getRepository()->findOneBy([... 'company' => …])` в обход
  репозитория и гейтов → метод репозитория.
- `Http/CashTransactionPage::canCreatePnlDocument()` — доменное правило →
  `CashTransaction`.
- `CashTransactionAutoRuleService` (807 строк) → чистый Matcher (unit без БД) +
  Applier с проверкой пар ЦФО–проект; при следующей задаче по автоправилам.

## Не делаем

Сверх списка первой волны:

- Массовый перенос `Service/` → `Application/Service/`.
- Перевод `#[ManyToOne] Company` (14 из 19 сущностей), `Counterparty`,
  `ProjectDirection` на строковые ID: правило нарушает больше половины кода —
  по `CLAUDE.md` пересматривается отдельным PR в правила (исключение для старых
  сущностей, новые — только `string $companyId`). Связи с
  `Finance\PLCategory`/`Document` — при касании, contract-задачей.

## Журнал

| Этап | Дата | Baseline Cash до | После | PR |
|---|---|---|---|---|
| — | 2026-11-11 | 138 записей | — | — |
