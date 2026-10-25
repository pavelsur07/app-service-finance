# bank-sync: автоматическая загрузка выписок и остатков из банков

Статус: план, ветка `feat/bank-sync`. Дата: 2026-10-25.
Класс: Large (новый модуль, схема, Messenger и cron, внешняя интеграция, изменение
границы между модулями). Ход работ — `checkpoint.md`, планы и итоги этапов —
`stages/stage-N.md`.

## 1. Задача

Компания подключает свой банк, после чего приложение само:

1. Получает доступ к банку способом, который этот банк поддерживает (OAuth 2.0
   партнёрского API, токен клиента, при необходимости mTLS).
2. Регулярно загружает выписки по привязанным счетам в ДДС без дублей, в том числе
   операции, проведённые банком задним числом.
3. Ежедневно сверяет остаток банка на конец дня с остатком учёта и показывает
   расхождения.

Банков будет несколько. Первый — Альфа-Банк.

## 2. Результат

- Новый модуль `src/BankSync`: подключения, учётные данные, адаптеры банков,
  расписание загрузки, остатки банка и сверка.
- `Cash` остаётся владельцем ДДС: создание транзакций, дедупликация, блокировка
  счёта, пересчёт остатков. Код банков `Cash` не знает.
- Зависимость в одну сторону: `BankSync → CashFacade`. PHP-импорта из `Cash` в
  `BankSync` нет.
- Второй банк добавляется новой папкой `Infrastructure/Bank/<Code>/` с адаптером и
  маппером, без правок в `Cash` и в общем коде загрузки.

## 3. Что уже есть (состояние кода на 2026-10-25, `a9eb74206`)

| Что | Где | Состояние |
|---|---|---|
| `BankConnection` (`cash_bank_connection`) | `Cash/Entity/Bank/` | `#[ManyToOne] Company`, `apiKey` открытым текстом, `varchar(255)`; UUID v4 из контроллера |
| `BankImportCursor` (`cash_bank_import_cursor`) | `Cash/Entity/Bank/` | unique (company, bank, account); `#[ManyToOne] Company` |
| `AlfaApiClient` | `Cash/Service/Import/Bank/Provider/Alfa/` | статичный Bearer; ошибки разделены на временные и постоянные; логи без тела и query. Эндпоинты с актуальной документацией не сверены |
| `BankStatementsProviderInterface` | там же | отдаёт сырой `array`; разбор формата Альфы — в `BankImportService` |
| `BankImportService` | `Cash/Service/Import/Bank/` | день счёта и курсор — одним коммитом; блокировка счёта `CashImportAccountLock`; lease журнала. **Не пересчитывает `money_account_daily_balance`** (импорт 1С пересчитывает: `ClientBank1CImportService:534`). Окно — курсор минус 1 день. `Uuid::uuid4()` для транзакций |
| `BankImportHandler` + `BankImportMessage` (`async_sync`) | `Cash/MessageHandler/Import/` | `match ('alfa')` захардкожен |
| `cash:bank:enqueue` | `Cash/Command/Import/` | в `docker/cron/app.cron` не стоит |
| Контроллер, форма, 3 шаблона, 2 Action подключения | `Cash/Controller/Import/BankConnectionController.php`, `templates/cash/bank_connection/`, пункт сайдбара | `findBy(['company' => …])` |
| Тесты | `tests/Unit/Cash/Service/Import/Bank/*`, `BankImportHandlerTest`, `AlfaApiClientTest`, `PersistenceActionsTest`, `CashCompanyIsolationTest` | — |
| На проде | по `docs/tasks/cash-architecture/plan.md`: 0 подключений, 0 `import_log` с `bank:*`, 0 сообщений в `failed` | **перепроверить перед миграцией** (Stage 1, п. 1.1) |

Что можно взять из других модулей:
- `Ingestion`: `SourceConnectorInterface` и `ConnectorRegistry` как образец реестра.
  `IngestionCredential` не подходит: `EncryptedJsonType` настроен на
  `PlaintextSecretCodec` (`config/services.yaml:171`), секреты лежат открытым JSON.
- `Shared\Security` — шифрование полей: `SodiumFieldEncryptionService`,
  `FileBasedSecretKeyProvider` (версии ключей из файла, `APP_ENCRYPTION_KEYS_JSON`
  или `APP_ENCRYPTION_FALLBACK_KEY`), `SecretRotationService`. Используют
  `Marketplace` (`ConnectionApiKeyCodec`, команды шифрования и ротации) и `MoySklad`
  (`ConnectionTokenCodec`, без команды ротации). Порядок смены ключа —
  `docs/maintenance/encryption-key-rotation.md` (описывает только маркетплейсы).
- `Shared\Service\Storage\ObjectStorageInterface` — для сырых ответов банка.
  `RawStorageFacade` требует `IngestSource` и запускает нормализацию маркетплейсов,
  поэтому не используется.
- Ночной гейт `app:cash:integrity-check` — образец гейта сверки.

## 4. Решения

| Вопрос | Решение | Почему |
|---|---|---|
| Где код | Новый модуль `BankSync` | Обсуждено с Владельцем 25.10, вариант B. Интеграции меняются по своим причинам; на проде нет данных, перенос дешёвый |
| Граница с `Cash` | `BankSync` вызывает `CashFacade`; `Cash` про `BankSync` не знает | Без циклов; правила ДДС в одном месте |
| Кто коммитит | `BankSync`-handler. Методы `CashFacade` только `persist()`; в `ARCHITECTURE.md` — «коммитит вызывающий» | CLAUDE.md, «Транзакции» |
| Формат данных адаптера | Нормализованные DTO (`BankAccountDTO`, `BankStatementLineDTO`, `BankDailyBalanceDTO`), сырой `array` за пределы адаптера не выходит | Иначе второй банк не встанет |
| День выписки | Все страницы дня собираются в память, потом один вызов `CashFacade` и коммит | HTTP-сбой случается до первого `persist()`, отсоединять недописанные строки больше не нужно |
| Вебхук или опрос | Опрос — источник правды; вебхук только ставит задание на загрузку | Вебхуки теряются и есть не у всех банков |
| Окно перечитывания | Последние 3 дня от курсора (настраивается на банк) | Задним числом и корректировки |
| Учётные данные | Общий `Shared\Security` (`FieldEncryptionServiceInterface`, sodium `secretbox`, ключи `APP_ENCRYPTION_*`, `key_version`); кодек по образцу `MoySklad\Infrastructure\Security\ConnectionTokenCodec`, ротация — команда по образцу `app:marketplace:rotate-connection-keys` | Механизм и ключи на проде уже есть; своего ключа не заводим |
| Старые таблицы | Новые таблицы `bank_sync_*` со `string $companyId`, старые удаляются, если на проде пусты | Не тащить `ManyToOne Company` в новый модуль |
| Сверка | Две проверки на день: полнота данных банка (входящий + приход − расход = исходящий) и совпадение остатка с учётом, допуск 0 | Первая ловит недогрузку, вторая — расхождение учёта |
| Исправление расхождений | Автоматически не исправляем; показываем и поднимаем гейт | Прод-деньги |

**Нужно от Владельца (не блокирует Stage 1):**
- Партнёрский доступ к API Альфа-Бизнес: договор, тестовая среда, документация,
  сертификат mTLS, если нужен. Без этого Stage 2 закрывает только общую часть, а
  OAuth Альфы ждёт документации.

**Принятые по умолчанию — поправить, если не так:**
- Ручной импорт (1С, файл) в счёт, привязанный к банку по API, не запрещается, а
  предупреждает о риске дублей (Stage 3).
- Сверка по рублёвым и валютным счетам одинаково, в валюте счёта.

## 5. Этапы

Каждый этап начинается с `stages/stage-N.md` из четырёх частей (задача, результат,
что считаем выполненным, чек-лист приёмки), итог дописывается туда же. Локальные
коммиты, push и Draft PR — одним шагом на handoff. Backend и frontend — разными
этапами.

| Stage | Что | Risk |
|---|---|---|
| 1 | Модуль `BankSync`, перенос кода из `Cash`, граница через `CashFacade`, DTO и реестр адаптеров, пересчёт остатков после импорта | HIGH-LOCAL (миграция, Messenger routing, граница модулей) |
| 2 | Учётные данные и авторизация: шифрование, статус подключения, OAuth 2.0 с обновлением токена, mTLS, привязка счетов банка к `MoneyAccount` | HIGH-LOCAL (секреты, auth, миграция) |
| 3 | Регулярная загрузка: cron (днём и утром), окно перечитывания, сырые ответы в хранилище, отменённые банком операции, предупреждение о ручном импорте в счёт с API | HIGH-LOCAL (cron, Messenger) |
| 4 | Остатки банка и сверка: `BankBalanceSnapshot`, проверки дня, ночной гейт, начальный остаток при первом подключении | HIGH-LOCAL (финансовая логика) |
| 5 | UI: подключения, привязка счетов, статус сверки на странице счетов (DS «Ваш Финдир») | MEDIUM |
| 6 (после документации банка) | Вебхуки Альфы, второй банк для проверки абстракции | HIGH-LOCAL |

Подробный план — в `stages/stage-N.md` по мере начала этапа. Stage 1 —
`stages/stage-1.md`.
