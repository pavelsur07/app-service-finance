# Fin‑Plan — Правила ведения и разработки проекта (Rules.md)

> Единый регламент по процессам, архитектуре, код-стайлу и неймингу для Fin‑Plan SaaS (Symfony 7 + React + PostgreSQL + Redis + Traefik). Документ обязателен к прочтению для всех участников.

---

## 0) TL;DR — чек‑лист

* Все изменения идут через **PR** в `main` (ветки `feature/*`, `fix/*`, `chore/*`).
* **Conventional Commits**; PR с чек‑листом, ревью минимум 1 апрувера.
* Docker dev≈prod; релизы через CI/CD; **staging перед prod**.
* **Документация обязательна**: README, миграции, ADR (решения), API (OpenAPI).
* **Тесты**: unit+integration на бизнес‑критичные участки (порог ≥70%).
* **Observability**: Monolog, Sentry, метрики; каналы логов по доменам.
* **Security**: RBAC, секреты вне кода, HTTPS, rate‑limits.
* **Нейминг по правилам ниже**; единый стиль PSR‑12, ESLint/Prettier.

---

## 1) Управление проектом

### 1.1 Цели, KPI, роадмап

* Ценности: скорость инсайтов, точность отчётов, надёжность интеграций.
* KPI: MRR, MAU, churn, LTV/CAC, NPS, TTR багов, время деплоя.
* Роадмап: горизонты 3–6–12 мес; квартальные OKR → задачи в трекере.

### 1.2 Бэклог и процессы

* Канбан/Jira/Linear. Каждая задача: **Описание → AC → DoD**.
* Приоритеты: безопасность > деньги клиента > данные > UX > nice‑to‑have.
* Еженедельно: планирование, демо, ретро (15–30 мин). Решения фиксируются в ADR.

### 1.3 Документация

* `README.md` (запуск), `CONTRIBUTING.md` (правила PR), `/docs/ADR-xxxx-*.md`.
* OpenAPI `/docs/api.yaml`; диаграммы в PlantUML/Mermaid.

---

## 2) Архитектура (Symfony/Backend)

### 2.1 Слои

* **Domain**: Entities, ValueObjects, Enums, Policies, Services (бизнес‑логика).
* **Application**: Use‑cases, Handlers, DTO/Request/Response, Events.
* **Infrastructure**: Doctrine Repositories, HTTP/CLI Adapters, Integrations, Storage.
* **Presentation**: Controllers (REST), CLI Commands, Twig/React endpoints.

### 2.2 Модульность

* Папки по доменам: `Finance/`, `Cashflow/`, `PL/`, `CRM/`, `Integration/`.
* Каждый модуль: `Entity/`, `Repository/`, `Service/`, `Controller/`, `Event/`, `Dto/`, `Tests/`.
* Multi‑tenant: каждый запрос проходит `CompanyContext`; все запросы к БД фильтруются по `company_id`.

### 2.3 Данные и миграции

* UUID v4 для ID. Важные таблицы — с индексами (`company_id`, `created_at` и FK).
* Снимки/агрегаты для отчётов (`*_snapshot`, `*_daily_balance`).
* Doctrine Migrations: 1 PR = 1 миграция (или серия миграций), откат описан.

---

## 3) Frontend (React)

* Файловая структура по фичам: `features/ChatCenter/`, `features/PL/`.
* Компоненты: dumb/presentational vs smart/containers; hooks для бизнес‑логики.
* TypeScript обязателен; RTK Query или SWR для данных; Socket.IO для realtime.
* Стили: Tailwind + shadcn/ui; единые токены бренда.

---

## 4) Observability & Security

* Monolog каналы: `auth`, `integration`, `finance`, `api`, `ai`.
* Sentry включён в prod и staging; релиз теги из CI.
* RBAC: роли `ROLE_USER`, `ROLE_ACCOUNTANT`, `ROLE_ADMIN`; проверка в контроллерах и политиках домена.
* Rate‑limits: публичные API, AI‑эндпоинты, интеграции.

---

## 5) CI/CD и окружения

* CI: линтеры (PHP-CS-Fixer/ESLint), тесты, миграции dry‑run, build образов.
* Staging: тест‑контур с анонимизированными данными.
* Prod: миграции только в рамках деплоя; feature‑флаги для инкрементальных релизов.

---

## 6) Нейминг: классы, методы, файлы, сущности

> **Главное правило**: имя отражает назначение и семантику; избегаем аббревиатур и префиксов, не несущих смысла. Язык — **английский** в коде, **русский** в UI/доках.

### 6.1 PHP / Symfony (Backend)

#### Классы (PascalCase)

* **Сущности Doctrine**: `MoneyAccount`, `CashTransaction`, `PLCategory`, `Company`.
* **Репозитории**: `<Entity>NameRepository` → `MoneyAccountRepository`.
* **Сервисы домена**: `<Noun><Action>Service` / `<Action>Service` → `CashflowAggregationService`, `PlNatureResolver`.
* **Менеджеры/Фасады**: `<Context>Manager|Facade` → `CompanyManager`, `ReportFacade`.
* **Хендлеры use‑case**: `<Action><Subject>Handler` → `CreateCashTransactionHandler`.
* **Контроллеры**: `<Subject>Controller` → `CashflowController` (методы — глагол+сущность).
* **DTO**: `<Subject><Direction>Dto` → `CashTransactionCreateDto`, `CashTransactionViewDto`.
* **ValueObject**: конкретное понятие → `Money`, `DateRange`, `TaxRate`.
* **Интерфейсы**: `*Interface` → `ChannelAuthInterface`, `PlCategoryProviderInterface`.
* **Трейты**: `*Trait` → `UuidPrimaryKeyTrait`, `TimestampableTrait`.
* **Исключения**: `<Context><Problem>Exception` → `AccessDeniedForCompanyException`.
* **События**: `<Subject><Action>Event` → `CashTransactionCreatedEvent`.
* **Подписчики**: `<Context>Subscriber` → `CashflowRecalculationSubscriber`.
* **Фабрики/Билдеры**: `<Subject>Factory|Builder` → `ReportQueryBuilder`.
* **Интеграции**: `<Provider><Component>` → `TelegramWebhookController`, `OzonOrdersApiClient`.

#### Методы (camelCase)

* **Команды/действия**: глагол в активе — `create`, `update`, `delete`, `calculate`, `aggregate`, `reconcile`, `sync`, `import`, `export`, `publish`.
* **Чтение**: `get*`, `find*`, `fetch*`, `list*` → `findById`, `findAllByCompany`, `fetchLatestSnapshot`.
* **Логические**: `is*`, `has*`, `can*`, `should*` → `isActive`, `hasBalance`, `canTransitionTo`.
* **Мутирующие коллекции**: `add*`, `remove*`, `replace*` → `addLineItem`, `removeMember`.
* **Доменные операции**: `apply*`, `calculate*`, `resolve*`, `rebuild*` → `resolvePlNature`, `calculateNetRevenue`.
* **Контроллеры (REST)**: `index`, `show`, `store`, `update`, `destroy` (или Symfony‑style: `list`, `create`, `edit`, `delete`).
* **Асинхрон/очереди**: `handle`, `process`, `on<Event>` → `handleMessage`, `processBatch`, `onCashTransactionCreated`.

#### Параметры и возвращаемые типы

* Явная типизация PHP 8.3; для денег — `Money`/`Decimal` VO, а не `float`.
* Коллекции — `iterable`/`array` с PHPDoc generic (`@var CashTransaction[]`).

### 6.2 База данных (PostgreSQL)

* Таблицы (snake_case, множественное число): `cash_transactions`, `money_accounts`, `pl_categories`.
* PK: `id` (uuid), FK: `<entity>_id`.
* Индексы: `idx_<table>_<column>`, уникальные: `uq_<table>_<columns>`.
* Ограничения/чек‑констрейнты: `chk_<table>_<rule>`.
* Миграции: файл `VersionYYYYMMDDHHMMSS.php` + понятное описание в PR.

### 6.3 Файлы и директории

* PSR‑4: `src/Module/...` по доменам. Пример: `src/Finance/Cashflow/Service/CashflowAggregationService.php`.
* Тесты зеркалят структуру `src/` → `tests/` с `*Test.php`.
* Конфиги: `config/packages/<bundle>.yaml`, `config/routes/*.yaml`.

### 6.4 Frontend (React/TS)

* Компоненты: PascalCase → `CashTable.tsx`, `TransactionForm.tsx`.
* Хуки: `useCamelCase` → `useCompanyContext`, `useCashflowFilters`.
* Типы/интерфейсы: PascalCase → `CashTransaction`, `MoneyAccount`.
* Сервисы/утилиты: camelCase → `formatMoney.ts`, `buildReportQuery.ts`.
* Стор: `features/<Feature>/slice.ts`, `selectors.ts`.
* Тесты: `*.test.tsx` рядом с кодом либо в `__tests__/`.

### 6.5 API/HTTP

* REST пути (kebab-case, множественное): `/api/cash-transactions`, `/api/money-accounts/{id}`.
* Параметры query: snake_case → `?company_id=...&date_from=...`.
* Статусы: 200/201/204/400/401/403/404/409/422/429/5xx.
* Ошибки: JSON `{ code, message, details }`.
* Версионирование: `/api/v1/...` (feature flags для инкрементов).

### 6.6 Названия веток и PR

* Ветки: `feature/pl-nature-resolver`, `fix/ozon-orders-dedup`, `chore/ci-cache`.
* Заголовок PR: по смыслу, на английском, с тикетом: `feat(pl): add PlNatureResolver to classify revenue/expense (#123)`.
* Шаблон PR содержит: цель, изменения, скриншоты/схемы, чек‑лист миграций и отката.

### 6.7 Коммиты (Conventional Commits)

* `feat:`, `fix:`, `docs:`, `chore:`, `refactor:`, `test:`, `perf:`, `build:`.
* Примеры:

    * `feat(pl): add PL aggregation by company-day`
    * `fix(cashflow): correct MoneyAccountDailyBalance update`
    * `test(api): add E2E for POST /cash-transactions`

### 6.8 Тесты: нейминг

* Методы тестов: `testShould...`, `testReturns...`, `testThrows...`.
* Фикстуры: понятные имена `CompanyFactory`, `CashTransactionFactory` (если используем factories) или `fixtures/CashTransactionsFixtures.php`.

### 6.9 Константы и enum

* Enum (PascalCase членов): `enum CashDirection { case INFLOW; case OUTFLOW; }`.
* Константы в классах: UPPER_SNAKE_CASE → `DEFAULT_TIMEOUT_SECONDS`.

### 6.10 Исключения и ошибки

* Исключения по домену: `CashTransactionValidationException`.
* 1 ошибка = 1 чёткий тип; не бросать `
  Exception` без контекста.

---

## 7) Доменные правила для Fin‑Plan

* **P&L природа операции**: классификатор `PlNatureResolver` определяет `REVENUE|EXPENSE|NEUTRAL` по признакам (категория, источник, контрагент, знак суммы, тип документа). Результат не хранится «на лету», а **сохраняется** в агрегате операции для воспроизводимости.
* **Разнесение по категориям**: `PLCategory` (дерево до 4–5 уровней), хранить `path`/`lft-rgt` или `parent_id` + материализованный путь.
* **Денежные остатки**: `MoneyAccountDailyBalance` пересчитывается событиями, не онлайн‑суммой.
* **Интеграции**: каждый провайдер в своём модуле; хранить внешние `external_id`, обеспечивать идемпотентность и дедупликацию.

---

## 8) Code Review правила

* Малые PR (до ~400 строк diff). Большие разбивать.
* Обязателен список проверок: миграции применяются? индексы есть? транзакционность? границы контекста? тесты?
* Нельзя смешивать рефакторинг и фичу, если это не препятствует релизу.

---

## 9) Style Guides

* **PHP**: PSR‑12, финальные классы по умолчанию, конструкторная инъекция, `readonly` там, где можно.
* **SQL**: явные JOIN, алиасы табличек в нижнем регистре (`ct`, `ma`).
* **JS/TS**: ESLint + Prettier, избегать any, prefer union/enum, узкие интерфейсы DTO.
* **Twig/HTML**: семантический HTML, доступность (aria‑*), BEM‑классы при необходимости.

---

## 10) Безопасность данных и мульти‑тенантность

* Все запросы фильтруются по `company_id` на уровне репозиториев/политик.
* В экспорт/отчёты не попадают чужие данные (обязательные тесты на изоляцию).
* Аудит изменений по критичным сущностям: кто/когда/что.

---

## 11) Шаблоны имен для типичных артефактов

* **Entity**: `CashTransaction`
* **Repository**: `CashTransactionRepository`
* **Service**: `CashflowAggregationService`
* **Resolver**: `PlNatureResolver`
* **Controller**: `CashTransactionController`
* **Request DTO**: `CashTransactionCreateDto`
* **Response DTO**: `CashTransactionViewDto`
* **Event**: `CashTransactionCreatedEvent`
* **Subscriber**: `CashflowRecalculationSubscriber`
* **Factory**: `CashTransactionFactory`
* **Exception**: `CashTransactionValidationException`
* **Command (CLI)**: `RebuildCashBalanceCommand`
* **Message/Job**: `RecalculatePlMessage`

---

## 12) Примеры нейминга методов (by intent)

* Создание: `createCashTransaction`, `createReport`, `createCompany`.
* Чтение: `findById`, `findAllByCompany`, `fetchDailyBalances`.
* Вычисление: `calculateNetRevenue`, `aggregateByCategory`, `resolvePlNature`.
* Проверки: `isSettled`, `hasSufficientFunds`, `canTransitionTo`.
* Интеграции: `syncOzonOrders`, `importWildberriesSales`, `publishTelegramMessage`.
* Обработка событий: `onCashTransactionCreated`, `onMoneyAccountUpdated`.

---

## 13) Жизненный цикл изменений

1. Issue/Task с AC/DoD → ветка `feature/...`.
2. Код + тесты + миграции + дока.
3. PR → CI зелёный → ревью → merge.
4. Deploy staging → ручная проверка → prod deploy.
5. Пост‑релизный мониторинг (Sentry/лог‑каналы).

---

## 14) Приложение: шаблон ADR (Architectural Decision Record)

```
# ADR-YYYYMMDD: <Короткое решение>
Context: ...
Decision: ...
Consequences: плюсы/минусы, риски, как откатывать.
Related: ссылки на PR/тикеты/доки.
```

---

**Последнее обновление:** синхронизировать дату при каждом правке. Ревью документа — раз в квартал.

---

## 15) Примеры на базе текущего проекта (конкретика)

> Ниже — готовые паттерны имён и сигнатур на материалах твоего кода, без выдумывания новых сущностей.

### 15.1 Finance / P&L

* **Resolver**: `PlNatureResolver`

    * Методы: `resolvePlNature(Document $document): PlNature`, `isRevenue(Document $document): bool`, `isExpense(Document $document): bool`.
* **Helper**: `PlCategoryHelper`

    * Методы: `guessCategory(Document $document): PLCategory`, `normalizeNodePath(PLCategory $category): string`.
* **Aggregator**: `PlAggregationService`

    * Методы: `aggregateByCompanyAndPeriod(Company $company, DateRange $period): PlAggregatedReport`, `rebuildForDocument(Document $document): void`.
* **Entity**: `Document`

    * Нейминг полей: `$company`, `$origin`, `$amount`, `$currency`, `$plCategory`, `$counterparty`, `$performedAt`.
* **Enum**: `PlNature { REVENUE, EXPENSE, NEUTRAL }`

### 15.2 Cashflow / ДДС

* **Entity**: `CashTransaction` (ID uuid, ссылки на `MoneyAccount`, `Company`)

    * Методы: `applySettlement(Money $amount): void`, `isOutflow(): bool`, `isInflow(): bool`.
* **Enum**: `CashDirection { INFLOW, OUTFLOW }`
* **Daily Snapshot**: `MoneyAccountDailyBalance`

    * Сервис: `DailyBalanceRebuildService::rebuildForPeriod(Company $company, DateRange $period): void`
    * Событие: `CashTransactionCreatedEvent` → Подписчик: `DailyBalanceProjectionSubscriber::onCashTransactionCreated()`

### 15.3 Интеграции маркетплейсов

* **Ozon**

    * Клиент: `OzonOrdersApiClient`

        * Методы: `fetchPostings(DateRange $period, int $limit = 100): OzonPostingCollection`, `fetchPostingByNumber(string $postingNumber): OzonPosting`.
    * Импортёр: `OzonOrdersImporter`

        * Методы: `importNew(DateRange $period): int`, `upsertPosting(OzonPosting $posting): void`.
* **Wildberries**

    * Клиент: `WildberriesReportsApiClient`
    * Импортёр: `WildberriesSalesImporter`

### 15.4 Контроллеры и DTO

* `CashTransactionController`

    * `list(Request $request): JsonResponse` `/api/cash-transactions`
    * `store(CashTransactionCreateDto $dto): JsonResponse`
    * `delete(Uuid $id): JsonResponse`
* DTO: `CashTransactionCreateDto` поля: `moneyAccountId`, `amount`, `direction`, `performedAt`, `description`, `counterpartyId?`, `categoryId?`

### 15.5 Исключения/валидация

* `InsufficientFundsException`, `CrossCompanyAccessException`, `InvalidPlCategoryException`.

---

## 16) Шаблон Pull Request (PR_TEMPLATE.md)

```
# What & Why
- Кратко, **что** меняем и **зачем** (бизнес‑контекст, ссылка на задачу).

# Changes
- [ ] Миграции Doctrine добавлены
- [ ] Индексы на частые фильтры (`company_id`, `created_at`) проверены
- [ ] Транзакционность/идемпотентность для импортов обеспечена
- [ ] Тесты: unit/integration добавлены/обновлены
- [ ] Документация/ADR обновлены (если меняется архитектура)

# Screenshots / Schemas
- Скриншоты UI/диаграммы

# Risk & Rollback
- Риски и как откатывать (миграции down, feature flag)

# Checklist
- [ ] CI зелёный  
- [ ] Покрытие критической логики ≥ 70%  
- [ ] Secrets не в коде  
- [ ] Multi‑tenant проверки (company_id) покрыты тестами
```

---

## 17) Шаблон Issue (ISSUE_TEMPLATE.md)

```
### Summary
Короткое описание задачи.

### Context
Почему это важно (бизнес/техника), ссылки на логи/Sentry/метрики.

### Acceptance Criteria
- [ ] ...
- [ ] ...

### Definition of Done
- [ ] Тесты зелёные
- [ ] Документация обновлена
- [ ] Развёрнуто на staging, проверено вручную

### Out of Scope
Что явно **не** делаем в этой задаче.
```

---

## 18) Чек‑лист для PR с миграциями БД

* [ ] Названия таблиц/индексов по правилам (`snake_case`, `idx_`, `uq_`)
* [ ] NOT NULL/DEFAULTS продуманы; денежные поля — `numeric(20,2)` или `decimal` в VO
* [ ] Внешние ключи с `ON DELETE RESTRICT|CASCADE` осознанно
* [ ] Индексы на `company_id` и `created_at`
* [ ] Данные исторических агрегатов не ломаются (скрипт backfill при необходимости)

---

## 19) ADR пример (заполненный)

```
# ADR-2025-09-30: Persisted PL Nature on Document

Context
Документы участвуют в P&L‑отчётах; онлайн‑калькуляция природы операции приводит к нефункциональным регрессиям при ретроспективных изменениях правил.

Decision
Вводим `PlNatureResolver`, результат `PlNature` сохраняем в документ при фиксации. При изменении правил пересобираем агрегаты через команду `RebuildPlAggregatesCommand`.

Consequences
+ Детерминированная отчётность, повторяемость
+ Ускорение выборок отчёта
− Нужна миграция и бэк‑филл

Related
PR #123, миграция `Version20250930153000`, задача FIN-PL-87
```

---

## 20) Глоссарий нейминга (кратко)

* **Resolver** — определяет/вычисляет один доменный атрибут (`PlNatureResolver`).
* **Aggregator/Projection** — строит агрегаты/проекции для отчётов (`PlAggregationService`, `DailyBalanceProjectionSubscriber`).
* **Importer/Synchronizer** — тянет и сохраняет внешние данные (`OzonOrdersImporter`).
* **Factory/Builder** — безопасно собирает сложные объекты (`ReportQueryBuilder`).
* **Subscriber** — реагирует на события, обновляет проекции/снапшоты.

---

## 21) Примеры Conventional Commits (твои кейсы)

* `feat(pl): add PlNatureResolver and persist nature on Document`
* `fix(cashflow): correct daily balance projection for outflow refunds`
* `perf(report): precompute company-day aggregates for PL`
* `chore(ci): enable php-cs-fixer and cache composer deps`
* `docs(rules): extend naming glossary and PR checklist`

---

## 22) Branch naming (твои кейсы)

* `feature/pl-nature-resolver`
* `feature/cash-daily-balance-rebuild`
* `fix/ozon-postings-dedup`
* `chore/ci-cache-speedup`

---

## 23) Шаблон описания миграции в PR

```
### Migration Notes
- Create table `pl_aggregates` (company_id, category_id, day, amount) with `idx_pl_aggregates_company_day`
- Add column `pl_nature` to `documents` (enum-like check)
- Backfill: `bin/console app:pl:backfill-nature --company=<uuid>`
- Rollback: drop column `pl_nature`, drop table `pl_aggregates`
```
