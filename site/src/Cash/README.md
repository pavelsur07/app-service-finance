# Модуль Cash (ДДС)

Денежные счета, операции ДДС, переводы, статьи ДДС, автоправила, платёжный
календарь, фонды и импорты выписок. Общие правила — `CLAUDE.md`, контракты —
`ARCHITECTURE.md` (раздел `CashFacade`), история приведения модуля к правилам —
`docs/tasks/cash-architecture/plan.md`.

## Границы модуля

**Что закрыто гейтом.** Слои `Service`, `Repository`, `Application` (кроме
`Application/DTO`) и `Infrastructure` Cash недоступны другим модулям —
`test_module_internals_are_closed_to_other_modules` в
`site/tests/Architecture/ModuleBoundaryRules.php`; Cash не зависит от
`App\Analytics` — `test_cash_does_not_depend_on_analytics`, и от `App\BankSync` —
`test_cash_does_not_depend_on_bank_sync`: подключения к банкам и их протоколы живут в
модуле `BankSync`, выписку он грузит методами `CashFacade` (`importBankStatementDay()`
и др., `ARCHITECTURE.md`, «BankSync → Cash: загрузка выписки»). Симметрично Cash не
импортирует `Service`, `Repository`, `Application` (кроме DTO) и `Infrastructure`
модулей Company и Finance.

**Как обращаться к Cash снаружи:** `App\Cash\Facade\CashFacade`, типы его контракта
(`App\Cash\Application\DTO\*`, `App\Cash\Enum\*`) и доменные события
`App\Cash\Domain\Event\*` (например, `CashTransactionsChanged` → сброс кэша дашборда в
Analytics).

**Как Cash обращается наружу:** фасады `CompanyFacade`, `CounterpartyFacade`,
`FinancialResponsibilityCenterFacade`, `FinanceFacade`, `PLCategoryFacade` и событие
`CompanyCreatedEvent`. В формах — `ChoiceType` со списком из фасада, не `EntityType` с
чужой сущностью.

**Legacy-исключения (слои `Entity`, `Enum`, `Domain`, `Exception` гейтом не закрыты):**

- снаружи читают сущности и исключения Cash: Finance (`Document` ↔ `CashTransaction`,
  `FinanceFacade`, `BalanceDynamicsQuery`, `DocumentController`), Telegram
  (`ClientBinding`, `CurrencyMismatchException`), `Report\Cashflow\CashflowReportBuilder`,
  `Company\…\CompanyOwnerMembershipCreator`, фикстуры;
- Cash держит ORM-связи на `Company`, `Counterparty`, `ProjectDirection`, `PLCategory` и
  `Finance\Entity\Document` (`CashTransaction::documents`), сам создаёт `Document` и
  `DocumentOperation` (`CashTransactionToDocumentService`), использует
  `Company\Domain\Service\CounterpartyNameNormalizer`, `CounterpartyPickerType`,
  `Company\Security\ModuleAccess`, enum'ы Company/Finance и DTO
  `FinancialResponsibilityCenter*`.

Новый код этих исключений не расширяет: ссылки на чужие сущности — `string $id`
(`CLAUDE.md`, «Entity»).

## Устройство

| Слой | Что здесь |
|---|---|
| `Controller` | HTTP in/out, компания — `ActiveCompanyService`, данные — через Repository с `companyId` или Action |
| `Application` | Action'ы — граница транзакции; `flush()` — только в Action, MessageHandler и Command (гейт `FlushOutsideTransactionBoundaryRule`), исключения в baseline — сервисы импорта выписок |
| `Application/DTO` | входы и результаты фасада |
| `Infrastructure/Query` | read-модели: для фасада — `CashDashboardQuery`, `CashReportQuery`; внутренние — `CashTransactionAutoRuleCandidateQuery` |
| `Repository` | доступ к своим сущностям; каждый метод-запрос принимает компанию (`RepositoryCompanyScopeRule`), санкционированные исключения помечены `@companyScopeExempt` |
| `Service` | legacy-сервисы модуля: `persist()` без `flush()`; исключение — сервисы импорта выписок (`flush` порциями, журнал импорта) |
| `Domain/Event` | синхронные события «данные ДДС изменились» — только для теряемых последствий (кэш) |
| `EventListener`, `EventSubscriber` | реакция на события Company и Doctrine (системные статьи, аудит, автоправила) |

Деньги — `App\Shared\Domain\ValueObject\Money` и decimal string; приведение
сумм к `float` ловит `MoneyFloatCastRule`.

## Мультивалютные счета и переводы

- Единый fiat-контракт Cash — `RUB`, `USD`, `EUR`, `KZT`. Он применяется к
  `BANK`, `CASH` и `EWALLET`; `CRYPTO_WALLET` в переводах не участвует.
- Валюта сохранённого счёта неизменяема, а валюта операции всегда совпадает с
  валютой её счёта. `PaymentPlan` без валюты остаётся RUB-only.
- `CashTransfer` — один агрегат над двумя техническими операциями: исходящая
  нога имеет одну split-строку `CF_TECH_OUT`, входящая — `CF_TECH_IN`. Эти
  системные категории всегда являются детьми `CF_TECH`.
- Same-currency перевод требует одинаковые суммы и не хранит FX metadata.
  Cross-currency v1 разрешает только `RUB↔USD` и `RUB↔EUR`. Пользователь
  передаёт обе фактические суммы; effective rate хранится как точный decimal
  scale 18 в направлении «валюта назначения за единицу валюты источника».
- Комиссия банка проводится отдельной обычной OUTFLOW-операцией. Внешний FX
  provider, автоматический расчёт одной суммы, `USD↔EUR`, переоценка остатков и
  сведение разных валют в RUB не входят в v1.
- Legacy-операции `isTransfer=true` без агрегата не спариваются автоматически и
  сохраняют прежнее поведение.

## Публичные операции и UI

- Создание выполняется только через
  `CashFacade::createTransfer(CreateCashTransferCommand)`; агрегат, обе ноги,
  splits, аудит и пересчёт счетов находятся в одной DB-транзакции.
- Удаление и восстановление выполняются через `CashFacade::deleteTransfer()` и
  `CashFacade::restoreTransfer()`. Отдельная мутация ноги запрещена во всех
  generic edit/delete/restore/split/bulk paths.
- UI расположен под `/finance/cash-transfers`; контроллеры только валидируют
  company scope/CSRF и делегируют финансовую логику фасаду.
- Cash dashboard и Home требуют явной валюты агрегирования; default — `RUB`.
  P&L widgets не зависят от Cash selector.

## Проверка целостности

```bash
php bin/console app:cash:verify-transfers
```

Команда строго read-only, обрабатывает детальные проверки пакетами компаний и
возвращает ненулевой exit code при нарушении pair/company/account/currency/
direction/category/rate/deletion/idempotency/orphan-инвариантов. Уникальность
idempotency key и владения ногами проверяется двумя глобальными aggregate scans,
чтобы видеть нарушения между пакетами. В логи идут только названия проверок и
счётчики — без UUID, сумм и реквизитов. Legacy `isTransfer=true` без агрегата
печатается как INFO и не делает проверку красной. Автоматического repair нет.

## Дерево категорий ДДС

- Обычная категория может быть root; её `flowKind` — `OPERATING`, `INVESTING` или `FINANCING`.
- Дочерние категории наследуют `flowKind` от root. Обычные потомки не разрешены в
  `CF_TECH`, `CF_TECH_IN`, `CF_TECH_OUT` и `CF_UNALLOC`.
- `app:cashflow-categories:migrate-system-structure` создаёт и нормализует только
  канонические системные категории. Она не перемещает обычные root-категории.
- Обычные legacy root с `TECHNICAL` не изменяются автоматически и отражаются в CLI только
  агрегированным warning без UUID, названий и реквизитов.

## Безопасный rollout

1. До production-действий открыть отдельный Production Gate. Проверить
   системную структуру категорий read-only командой
   `app:cashflow-categories:migrate-system-structure --companies-with-accounts`
   без `--execute`. Команда выбирает только компании со счетами ДДС и выводит
   агрегированные счётчики без UUID и реквизитов. Обычные root-категории не входят
   в execute-план; warning о legacy `TECHNICAL` root остаётся read-only.
2. Применить expand-only migration `cash_transfer`, затем развернуть application
   code. Backfill и автоматическое спаривание legacy-операций не нужны.
3. После deploy выполнить smoke создания/просмотра на разрешённой паре и
   read-only `app:cash:verify-transfers`; любой FAIL блокирует дальнейший rollout
   и требует отдельного анализа, а не автоматического исправления.
4. До создания первого агрегата безопасен обычный rollback application code с
   сохранением expand-таблицы. После появления агрегатов нельзя откатываться на
   код без pair-mutation guards: предпочтителен forward fix; при вынужденном
   rollback сначала блокируются Cash write-paths.

Production migration, category `--execute`, smoke с записью и rollback всегда требуют
отдельного явного разрешения Владельца; merge и deploy — по одному «merge and deploy»
(`AGENTS.md` §3.2).
