# Stage 1: модуль BankSync и граница с Cash

Risk: HIGH-LOCAL (миграция, routing Messenger, граница модулей, перенос кода из `Cash`).
stage_base_commit: записать перед первым Work item.

## 1. Какую задачу решаем

Банковская загрузка сейчас живёт внутри `Cash` и завязана на формат Альфы:
`BankImportService` разбирает сырой ответ Альфы, handler выбирает банк через
`match ('alfa')`, сущности держат `#[ManyToOne] Company`. Второй банк в эту схему не
встанет, а учёт ДДС смешан с интеграцией. Кроме того, импорт из банка не
пересчитывает `money_account_daily_balance`: остатки по счёту после загрузки
устаревают, и сверка из Stage 4 показала бы ложные расхождения.

## 2. Что будет результатом

Поведение для пользователя не меняется: подключение Альфы с токеном и загрузка
выписки по команде работают как сейчас. Меняется устройство:

```
site/src/BankSync/
  Domain/
    BankCode.php                         enum: alfa
    BankProviderInterface.php            code(), getAccounts(), getStatementDay() → DTO
  Application/
    DTO/BankAccountDTO, BankStatementLineDTO, BankStatementDayDTO
    SaveBankConnectionAction, DeleteBankConnectionAction
    SyncCompanyBankAccountsAction        бывший BankImportService без создания транзакций
  Infrastructure/Bank/
    BankProviderRegistry.php             tagged locator по BankCode
    Alfa/AlfaApiClient.php               перенесён без изменения поведения
    Alfa/AlfaStatementMapper.php         сырой ответ Альфы → DTO (разбор из BankImportService)
    Alfa/AlfaBankProvider.php
  Infrastructure/Security/
    BankCredentialsCodec.php             учётные данные ↔ EncryptedPayload (Shared\Security)
  Entity/   BankConnection (bank_sync_connection), BankImportCursor (bank_sync_import_cursor)
  Repository/, Exception/ (BankApiUnavailableException, BankApiRequestFailedException)
  Message/SyncBankConnectionMessage.php  → async_sync
  MessageHandler/SyncBankConnectionHandler.php   точка входа, владеет транзакцией
  Command/EnqueueBankSyncCommand.php     bank-sync:enqueue --bank=alfa
  Controller/, Form/                      подключения, маршруты bank_sync_connection_*
site/templates/bank_sync/connection/     перенесены из templates/cash/bank_connection/
```

Новые методы `CashFacade` (типы — `App\Cash\Application\DTO\*`, всё только
`persist()`, коммитит вызывающий):

| Метод | Что делает |
|---|---|
| `findStatementImportAccount(string $companyId, string $accountNumber): ?StatementImportAccountDTO` | Денежный счёт компании по номеру: id и валюта |
| `tryLockAccountForStatementImport(string $companyId, string $moneyAccountId): bool` / `releaseAccountStatementImportLock(...)` | Та же `CashImportAccountLock`, что у импорта 1С и файла |
| `startStatementImportLog(string $companyId, string $source): string` / `finishStatementImportLog(string $importLogId, StatementImportSummaryInput $summary)` | Журнал `import_log` и его lease, как сейчас в `BankImportService` |
| `importBankStatementDay(string $companyId, string $moneyAccountId, string $source, BankStatementDayInput $day): BankStatementDayResult` | Строки дня → `CashTransaction` (UUID v7, дедупликация в пачке и по `uniq_cashflow_import`, проверка валюты, проект и ЦФО по умолчанию) и пересчёт `money_account_daily_balance` за затронутые дни в той же транзакции |

Handler на каждый день счёта: собрать все страницы через адаптер → один
`wrapInTransaction()`: `importBankStatementDay()` + сдвиг курсора. День, транзакции,
пересчёт остатков и курсор фиксируются вместе. HTTP-вызовы до транзакции.

Из `Cash` удаляются: `Entity/Bank/*`, `Repository/Bank/*`, `Service/Import/Bank/*`,
`MessageHandler/Import/BankImportHandler`, `Message/Import/BankImportMessage`,
`Command/Import/BankEnqueueImportCommand`, `Controller/Import/BankConnectionController`,
`Form/Bank/*`, `Application/{Save,Delete}BankConnectionAction`, банковские исключения,
`templates/cash/bank_connection/`.

## 3. Что считаем выполненной работой

- Код из таблицы выше в `BankSync`, в `Cash` банковского кода не осталось
  (`grep -ri "alfa\|BankConnection" site/src/Cash` пуст, кроме `CashFacade` и DTO
  импорта выписки без упоминания банка).
- `BankSync` импортирует из `Cash` только `Facade` и `Application\DTO`; гейт
  `ModuleBoundaryRules` зелёный.
- Миграция: новые таблицы `bank_sync_connection`, `bank_sync_import_cursor`
  (`company_id` строкой, индекс `(company_id, bank_code)`, unique курсора как
  сейчас), удаление `cash_bank_connection`, `cash_bank_import_cursor`. `down()`
  восстанавливает старые таблицы пустыми. Удаление — только при подтверждённых 0
  строк на проде (п. 1.1), иначе перенос данных в той же миграции с
  шифрованием токена.
- Учётные данные зашифрованы с первого дня: в `bank_sync_connection` нет колонки с
  открытым токеном. `credentials_encrypted` (JSON `EncryptedPayload`: шифротекст,
  версия ключа, время) + `credentials_key_version` для выборки при ротации; внутри
  шифротекста — JSON `{type: "static_token", token}`, в Stage 2 добавится тип
  `oauth2`. Шифрует и расшифровывает только `BankCredentialsCodec` через
  `FieldEncryptionServiceInterface`, после шифрования — контрольная расшифровка
  (как `MoySklad\…\ConnectionTokenCodec`). Сущность хранит только шифротекст.
- Импорт банка пересчитывает остатки счёта — регрессионный тест, красный на
  старом коде.
- Тесты переписаны на новые классы, покрытие не меньше прежнего (см. чек-лист).
- `ARCHITECTURE.md`: строка `BankSync` в таблице модулей, раздел «BankSync →
  Cash: загрузка выписки» (кто коммитит, порядок блокировок: счёт →
  `wrapInTransaction` дня → пересчёт остатков), новые методы `CashFacade`, правка
  «Cash: идемпотентность файлового импорта» (абзацы про `BankImportService`),
  `Changelog`. Таблица «Модули» больше не пишет «банковский импорт» у `Cash`.
- Конфиги: `routes.yaml`, `doctrine.yaml`, `twig.yaml`, `messenger.yaml`
  (routing `SyncBankConnectionMessage` → `async_sync`, старая строка удалена),
  `MessengerRoutingTest` зелёный.
- Режим «токен клиента» Альфы переносится как есть ради неизменного поведения, но
  по документации это Alfa API Direct (только собственная организация партнёра,
  `plan.md` §3a): новых подключений Альфы в этом режиме не ждём, замена — Alfa ID в
  Stage 2.
- Явно не входит: OAuth, статусы подключения и ротация ключей (Stage 2), cron и
  окно перечитывания (Stage 3), остатки банка и сверка (Stage 4), новый UI
  (Stage 5).

## 4. Work items

- **1.1** Прод, read-only: число строк `cash_bank_connection`,
  `cash_bank_import_cursor`, `import_log` с `source LIKE 'bank:%'`, сообщения
  `BankImportMessage` в `messenger_messages` и в Redis-очереди `async_sync`.
  Записать в `stages/stage-1.md`. Не ноль — перенос данных вместо удаления и
  обработчик старого класса сообщения до опустения очереди. Там же: активная
  версия ключа шифрования на проде —
  `bin/console app:marketplace:rotate-connection-keys` без `--execute` (печатает
  только версию и счётчики). Ключи на прод доставляет `deploy.yml`
  (`APP_ENCRYPTION_*`), проверка подтверждает, что они доехали.
- **1.2** Baseline: `make site-stan` (число ошибок), тесты
  `tests/Unit/Cash/Service/Import/Bank`, `tests/Unit/Cash/MessageHandler/Import`,
  `tests/Functional/Cash/Controller/CashCompanyIsolationTest.php` — записать.
- **1.3** Каркас модуля и конфиги (`docs/workflow/new-module.md`), `BankCode`,
  `BankProviderInterface`, DTO, `BankProviderRegistry` (тег через
  `#[AutoconfigureTag]`).
- **1.4** `AlfaStatementMapper`: перенос разбора из `BankImportService`
  (`extractAccountNumbers`, `extractTransactions`, `hasNextPage`, даты, суммы
  строкой без `float`, направление, валюта, назначение). Unit-тесты на фикстурах
  ответов, которые сейчас лежат в `BankImportServiceTest`.
- **1.5** `AlfaApiClient` и `AlfaBankProvider` в `BankSync`; `AlfaApiClientTest`
  переносится. Исправление по спецификации (`plan.md` §3a): путь выписки
  `/api/jp/v1/statement/transactions` (сейчас без `/api` — запрос уходит мимо
  API) и явный `limit` на страницу; `ErrorDescription` банка в лог и сообщения
  исключений не попадает (в нём номер счёта).
- **1.6** Методы `CashFacade` и Input/DTO; внутренний сервис импорта дня в `Cash`
  (создание транзакций из `BankImportService::importAccount`) +
  `AccountBalanceService::recalculateDailyRange` за дни дня. Проверить, как импорт
  1С уведомляет Analytics (`CashTransactionsChanged`), и сделать так же.
- **1.7** Сущности и репозитории `BankSync`, миграция; `findActiveByBankCode` —
  `@companyScopeExempt` (cron по всем компаниям), остальное — с `companyId`.
  `BankCredentialsCodec`; форма подключения пишет токен через кодек,
  `AlfaApiClient` получает токен от кодека, а не из сущности; при редактировании
  пустое поле токена оставляет прежний.
- **1.8** `SyncCompanyBankAccountsAction`, `SyncBankConnectionMessage`,
  handler (логи старта и финиша с `companyId`, временный сбой → `warning` и
  исходное исключение, отказ банка → `error` + `Unrecoverable…` — как сейчас),
  команда `bank-sync:enqueue`.
- **1.9** Контроллер, форма, шаблоны, Action подключения; маршруты
  `bank_sync_connection_*`, путь `/bank-connections` прежний; сайдбар;
  `getActiveCompany()` и поиск подключения только с компанией.
- **1.10** Удаление старого кода из `Cash`, перенос тестов, документация.

## 5. Чек-лист приёмки

Тесты (в том же Stage):
- [ ] `AlfaStatementMapper`: счета, строки, следующая страница, приход/расход,
      неизвестное направление, нет `uuid`/даты/суммы → строка пропущена с
      причиной.
- [ ] `BankProviderRegistry`: известный код → адаптер, неизвестный → исключение.
- [ ] **Регрессия** `AlfaApiClient`: URL выписки — `{base}/api/jp/v1/statement/transactions`
      с `limit` (красный на `a9eb74206`); тело ошибки банка не попадает в лог.
- [ ] `CashFacade::importBankStatementDay`: happy path; повторный вызов того же дня →
      те же транзакции, число строк не меняется; дубль `uuid` внутри дня → одна
      строка; чужая валюта → ошибка в результате, строка не создана; счёт чужой
      компании → исключение.
- [ ] **Регрессия:** после импорта дня `money_account_daily_balance` на эти дни
      пересчитан (тест красный на `a9eb74206`).
- [ ] Handler: временный сбой банка → исключение для retry, курсор не сдвинут;
      отказ банка → `Unrecoverable…`; нет подключения → `Unrecoverable…`; занятый
      счёт → пропуск с `warning`, курсор не сдвинут.
- [ ] Сбой посреди второго дня: первый день сохранён вместе с курсором, второй —
      ничего (functional, реальная БД).
- [ ] `BankCredentialsCodec`: шифрование и расшифровка возвращают исходное; в
      колонке нет открытого токена (functional: строка из БД не содержит токена);
      неизвестная версия ключа → исключение без токена в сообщении.
- [ ] Изоляция: подключение другой компании — 404 на `edit`/`delete`/`sync`
      (перенос из `CashCompanyIsolationTest`).

Гейты и проверки:
- [ ] `make site-stan` — baseline не вырос (переименования учитывает guard, новые
      ошибки чинятся, а не добавляются).
- [ ] `make site-cs-check`, `make site-cs-strict-types`.
- [ ] Модульные тесты `BankSync` и `Cash`; `MessengerRoutingTest`;
      `ModuleBoundaryRules`.
- [ ] Миграция: `up` → `down` → `up` на локальной БД.
- [ ] Ручной smoke на стенде: страница подключений открывается, создание и
      удаление подключения, `bank-sync:enqueue --bank=alfa` ставит задание
      (против заглушки HTTP, боевого доступа к Альфе нет).
- [ ] `ARCHITECTURE.md` и `site/src/Cash/README.md` обновлены, `Changelog`.
- [ ] Внутреннее ревью полного diff от `stage_base_commit`; внешнее ревью — да
      (HIGH-LOCAL).

Reviewer focus:
- граница: в `BankSync` нет импорта `App\Cash\{Entity,Repository,Service,Infrastructure}`;
- транзакция дня: HTTP вне `wrapInTransaction`, пересчёт остатков внутри;
- порядок блокировок: блокировка счёта (сессионная) → транзакция дня → блокировка
  пересчёта счёта;
- IDOR в новых репозиториях и контроллере;
- ничего из ответа банка (номер счёта, назначение) не уходит в `error`-логи и
  сообщения исключений;
- токен не появляется в логах, исключениях, дампе формы и шаблонах
  (`#[\SensitiveParameter]` на параметрах с токеном).

## Итог этапа

Дописывается после выполнения.
