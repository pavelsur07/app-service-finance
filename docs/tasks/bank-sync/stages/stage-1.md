# Stage 1: модуль BankSync и граница с Cash

Risk: HIGH-LOCAL (миграция, routing Messenger, граница модулей, перенос кода из
`Cash`, секреты).
stage_base_commit: `23491e8cf` (ветка `feat/bank-sync`, база с `master` —
`a9eb74206`).
Поставка: A (Stage 1–3, `plan.md` §5).

## 1. Цель этапа

Отделить интеграцию с банками от учёта ДДС: новый модуль `BankSync` получает
выписку из банка и отдаёт её `Cash` через `CashFacade`, а `Cash` больше ничего не
знает о банках. Второй банк после этого добавляется новой папкой адаптера.
Попутно закрываются два дефекта старого кода:
- импорт из банка не пересчитывал остатки счёта (`money_account_daily_balance`);
- путь выписки Альфы без `/api` (запрос уходил мимо API).

Учётные данные банка с первого дня хранятся только зашифрованными.

Для пользователя поведение не меняется: страница «Банки / Подключения» по тому же
адресу, подключение Альфы с токеном, загрузка по кнопке и командой.

## 2. Результат

```
site/src/BankSync/
  Enum/BankCode.php                         alfa
  Domain/BankProviderInterface.php          code(), listAccounts(), fetchStatementDay() → DTO
  Domain/BankAccess.php                     baseUrl + токен для адаптера (не логируется)
  Application/DTO/                          BankAccountDTO, BankStatementLineDTO, BankStatementDayDTO,
                                            BankConnectionFormData
  Application/SaveBankConnectionAction.php  создание/изменение, токен — через кодек
  Application/DeleteBankConnectionAction.php
  Infrastructure/Bank/BankProviderRegistry.php      tagged locator по BankCode
  Infrastructure/Bank/Alfa/AlfaApiClient.php        перенесён; путь /api, X-TraceId
  Infrastructure/Bank/Alfa/AlfaStatementMapper.php  сырой ответ Альфы → DTO
  Infrastructure/Bank/Alfa/AlfaBankProvider.php
  Infrastructure/Security/BankCredentialsCodec.php  {type, token} ↔ EncryptedPayload
  Entity/BankConnection.php                 bank_sync_connection, string $companyId
  Entity/BankImportCursor.php               bank_sync_import_cursor
  Repository/                               с companyId; findActiveByBankCode — @companyScopeExempt
  Exception/                                BankApiUnavailableException, BankApiRequestFailedException,
                                            UnknownBankCodeException
  Message/SyncBankConnectionMessage.php     → async_sync
  MessageHandler/SyncBankConnectionHandler.php      точка входа, владеет транзакциями
  Command/EnqueueBankSyncCommand.php        bank-sync:enqueue --bank=alfa
  Controller/Connection*Controller.php      по одному действию; маршруты bank_sync_connection_*
  Form/BankConnectionType.php               на BankConnectionFormData
site/templates/bank_sync/connection/        перенесены из templates/cash/bank_connection/
```

Новое в `Cash` (только контракт для `BankSync`, о банках ничего):

| Метод `CashFacade` | Что делает | Кто коммитит |
|---|---|---|
| `findStatementImportAccount(companyId, accountNumber): ?StatementImportAccount` | Денежный счёт компании по номеру: id и валюта | — (чтение) |
| `tryLockAccountForStatementImport(companyId, moneyAccountId): bool` / `releaseAccountStatementImportLock(...)` | Та же сессионная `CashImportAccountLock`, что у 1С и файла | — |
| `startStatementImportLog(companyId, source): string` | `import_log` + lease журнала | вызывающий |
| `finishStatementImportLog(companyId, importLogId, StatementImportSummary)` | Счётчики, итог, `finished_at`; снимает lease | вызывающий |
| `importBankStatementDay(companyId, moneyAccountId, source, BankStatementDayInput): BankStatementDayResult` | Строки дня → `CashTransaction` (UUID v7, дубли в пачке и по `uniq_cashflow_import`, проверка валюты, проект и ЦФО по умолчанию), `flush()` и пересчёт `money_account_daily_balance` от первого дня строк по сегодня. Только внутри транзакции вызывающего | вызывающий (`wrapInTransaction`) |

Реализация импорта дня — `Cash\Application\ImportBankStatementDayAction`
(Action вызывается из Facade, своей транзакции не открывает; `flush()` внутри
транзакции вызывающего нужен, потому что пересчёт остатков читает строки из БД).

Поток handler на одно подключение:
1. журнал импорта — `startStatementImportLog` → `flush()`;
2. счета банка (HTTP) → для каждого счёта: денежный счёт по номеру, блокировка
   счёта;
3. для каждого дня от курсора − 1 (первый раз — 7 дней назад) по сегодня: все
   страницы дня (HTTP) → `wrapInTransaction`: `importBankStatementDay` + сдвиг
   курсора;
4. журнал — `finishStatementImportLog` → `flush()`; при сбое журнал закрывается с
   ошибкой, если EM открыт, исключение пробрасывается.

Из `Cash` удаляются: `Entity/Bank/*`, `Repository/Bank/*`, `Service/Import/Bank/*`,
`MessageHandler/Import/BankImportHandler`, `Message/Import/BankImportMessage`,
`Command/Import/BankEnqueueImportCommand`, `Controller/Import/BankConnectionController`,
`Form/Bank/*`, `Application/{Save,Delete}BankConnectionAction`, банковские
исключения, `templates/cash/bank_connection/`, их записи в PHPStan baseline.

Миграция: создать `bank_sync_connection`, `bank_sync_import_cursor`; удалить
`cash_bank_connection`, `cash_bank_import_cursor` (на проде пусты — п. 1.1).
`down()` восстанавливает старые таблицы пустыми и удаляет новые.

### Решения, уточнённые при детализации

- **Оркестрация — в handler, а не в `SyncCompanyBankAccountsAction`.** Транзакциями
  сценария владеет точка входа; Action, вызванный из handler, коммитить не может,
  а передавать `$commit`-замыкание в сервис (как в старом коде) — непрозрачно.
  Handler держит шаги 1–4 явно.
- **Контроллеры — по одному действию** (`CLAUDE.md`, «Слои»): Index, New, Edit,
  Delete, EnqueueSync. Пути и тексты прежние, имена маршрутов —
  `bank_sync_connection_*`.
- **Форма — на `BankConnectionFormData`**, а не на сущности: у сущности нет поля с
  открытым токеном. Пустой токен при редактировании оставляет прежний.
- **Логи:** строка выписки и номер счёта в `warning`/`error` не попадают (старый код
  писал тело операции в `warning` при пропуске строки без `uuid`); в контексте —
  `bank_connection_id`, `money_account_id`, счётчики. `X-TraceId` ответа банка — в
  `info`/`warning` запроса.
- **Analytics:** импорт 1С событие `CashTransactionsChanged` не отправляет; банковский
  импорт до сих пор тоже. Оставляем так же (поведение не меняется), FOLLOW-UP —
  отдельно решить для всех импортов.
- **Сумма строки:** без `(float)` — строка нормализуется `bcadd`, число из JSON —
  `number_format` (как было).
- Путь списка счетов `open-banking/v1.3` остаётся до Stage 6 (замена на v3.0 —
  там, вместе с привязкой счетов).

## 3. Что считаем выполненной работой (Definition of Done)

- Код из раздела 2 в `BankSync`; в `Cash` банковского кода нет:
  `rg -i "alfa|BankConnection|bank_connection" site/src/Cash site/templates/cash`
  пуст (кроме DTO и методов импорта выписки без упоминания банка).
- `BankSync` импортирует из `Cash` только `Facade` и `Application\DTO`;
  `ModuleBoundaryRules` зелёный; `Cash` не импортирует `BankSync`.
- Импорт дня атомарен: строки, пересчёт остатков и курсор — одна транзакция; HTTP —
  до неё.
- Импорт банка пересчитывает остатки счёта — регрессионный тест красный на старом
  коде.
- URL выписки Альфы — `{base}/api/jp/v1/statement/transactions` — регрессионный
  тест красный на старом коде.
- Токен хранится только в шифротексте; в строке БД его нет; в логах, исключениях и
  шаблонах тоже.
- Конфиги: `routes.yaml`, `doctrine.yaml`, `twig.yaml`, `messenger.yaml` (routing
  `SyncBankConnectionMessage` → `async_sync`, строки `BankImportMessage` нет),
  сайдбар на новые маршруты.
- Миграция `up` → `down` → `up` на локальной БД; схема совпадает с маппингом
  (`doctrine:schema:validate`).
- `ARCHITECTURE.md`: модуль `BankSync` в таблице модулей, раздел «BankSync → Cash:
  загрузка выписки» (кто коммитит, порядок блокировок), новые методы `CashFacade`,
  правка разделов `Cash` про банковский импорт, `Changelog`;
  `site/src/Cash/README.md` — без банковского импорта.

## 4. Work items

- **1.1** ✅ Прод, read-only (26.10): `cash_bank_connection` 0, `cash_bank_import_cursor`
  0, `import_log` с `bank:%` 0, `BankImportMessage` в `messenger_messages` 0;
  `messenger:stats` — все очереди 0, `failed` 0. Ключ шифрования на проде работает:
  11 подключений маркетплейсов на версии `v1` (`app:marketplace:rotate-connection-keys`
  через wrapper не разрешён — проверено SQL по `api_key_key_version`). Вывод:
  старые таблицы удаляются без переноса, обработчик старого сообщения не нужен.
- **1.2** ✅ Baseline: тесты банковского импорта, `PersistenceActionsTest`,
  `CashCompanyIsolationTest`, `AccountStrictMatchTest` — 99 тестов, OK (локальная
  БД `app_test_banksync`, сеть `asf1-net`). PHPStan — на изменённых файлах в конце
  этапа; записи baseline старых банковских файлов удаляются вместе с файлами.
- **1.3** Каркас: конфиги модуля, `BankCode`, `BankProviderInterface`, `BankAccess`,
  DTO, `BankProviderRegistry`, исключения.
- **1.4** `AlfaStatementMapper` + unit-тесты (фикстуры — из `BankImportServiceTest`
  и примеров спецификации).
- **1.5** `AlfaApiClient` (перенос, `/api`, `X-TraceId`), `AlfaBankProvider` (все
  страницы дня); перенос `AlfaApiClientTest` + регрессия пути.
- **1.6** `Cash`: DTO, `ImportBankStatementDayAction`, методы `CashFacade`;
  integration-тесты импорта дня (happy path, повтор, дубль, валюта, чужой счёт,
  пересчёт остатков — регрессия).
- **1.7** Сущности, репозитории, миграция; `BankCredentialsCodec` + тесты.
- **1.8** `SyncBankConnectionMessage`, `SyncBankConnectionHandler`,
  `EnqueueBankSyncCommand`; unit-тесты handler и integration «сбой на втором
  дне».
- **1.9** Форма, Action подключения, контроллеры, шаблоны, сайдбар; перенос
  проверок изоляции.
- **1.10** Удаление старого кода из `Cash`, baseline, `ARCHITECTURE.md`, README,
  чек-лист, Stage Report.

## 5. Чек-лист приёмки

Тесты (в том же Stage):
- [ ] `AlfaStatementMapper` — unit: счета (уникальные, пустые пропущены); строки
      приход/расход; следующая страница по `_links`; строка без `uuid`/даты/суммы/с
      неизвестным направлением пропущена и посчитана; `transactionId` разобран;
      сумма строкой и числом.
- [ ] `BankProviderRegistry` — unit: известный код → адаптер; неизвестный →
      `UnknownBankCodeException`.
- [ ] **Регрессия** `AlfaApiClient` — unit: URL выписки содержит `/api/jp/v1/…`
      (красный на старом коде); 429/5xx → `BankApiUnavailableException`; 401 →
      `BankApiRequestFailedException`; тело ответа и query не попадают в лог;
      `X-TraceId` в логе.
- [ ] `AlfaBankProvider` — unit: собирает все страницы дня, останавливается без
      `next`.
- [ ] `CashFacade::importBankStatementDay` — integration: happy path; повтор того
      же дня → то же число строк; дубль `externalId` внутри дня → одна строка;
      чужая валюта → ошибка в результате, строка не создана; счёт чужой компании →
      исключение; вызов без открытой транзакции → исключение.
- [ ] **Регрессия** — integration: после импорта дня `money_account_daily_balance`
      пересчитан (красный на старом коде: старый `BankImportService` не
      пересчитывал).
- [ ] Handler — unit: временный сбой → исходное исключение для retry; отказ банка →
      `Unrecoverable…`; нет подключения → `Unrecoverable…`; неизвестный банк →
      `Unrecoverable…`; занятый счёт → пропуск с `warning`; счёт без денежного
      счёта → пропуск.
- [ ] Сбой на втором дне — integration: первый день и курсор сохранены, второго
      дня нет, журнал закрыт с ошибкой.
- [ ] `BankCredentialsCodec` — unit + integration: шифрование и расшифровка
      сходятся; в строке БД нет токена; неизвестная версия ключа → исключение без
      токена в сообщении.
- [ ] Контроллеры — functional: страницы открываются; создание шифрует токен;
      редактирование с пустым токеном оставляет прежний; чужое подключение → 404
      на `edit`/`delete`/`enqueue`; запись без `FINANCE_WRITE` → 403.
- [ ] `MessengerRoutingTest` зелёный.

Гейты и проверки:
- [ ] PHPStan по изменённым файлам без новых ошибок; baseline только сократился.
- [ ] `make site-cs-check`, `make site-cs-strict-types` по изменённым файлам.
- [ ] Модульные тесты `BankSync` и затронутые `Cash`; `ModuleBoundaryRules`.
- [ ] Миграция `up` → `down` → `up`; `doctrine:schema:validate`.
- [ ] Ручной smoke на стенде: страница подключений, создание, редактирование,
      удаление, «Запустить импорт сейчас» ставит сообщение (без боевого банка).
- [ ] `ARCHITECTURE.md`, `site/src/Cash/README.md`, `Changelog`.
- [ ] Внутреннее ревью полного diff от `stage_base_commit`; внешнее ревью (HIGH-LOCAL,
      diff > 500 строк).

Reviewer focus:
- граница: в `BankSync` нет импорта `App\Cash\{Entity,Repository,Service,Infrastructure,Enum}`;
- транзакция дня: HTTP вне `wrapInTransaction`, пересчёт остатков внутри;
- порядок блокировок: блокировка счёта (сессионная) → транзакция дня → блокировка
  пересчёта счёта;
- IDOR в репозиториях и контроллерах;
- ничего из ответа банка (номер счёта, назначение) не уходит в `warning`/`error` и
  сообщения исключений;
- токен не появляется в логах, исключениях, форме и шаблонах
  (`#[\SensitiveParameter]`).

## Итог этапа

Дописывается после выполнения.
