## Stage 3: ручной запуск из UI — DONE

**Риск:** 🟢 LOW
**Owner gate:** no
**Release candidate:** yes
**Independently deployable:** yes
**Следующее действие:** 🛑 STOP — Release Gate, решение Владельца

### Scope Stage
- Stage base commit: `27d1236dda05482881171b256566e1b0f5c991ec`
- Work items completed: `3.1`–`3.4`

### Что сделано

Второй триггер загрузки каталога — кнопка «Обновить каталог Ozon» на странице
листингов, плюс журнал прогонов и взаимное исключение обходов.

- `SyncOzonListingCatalogController` — один action, `__invoke`,
  `POST /marketplace/listings/sync-ozon-catalog`, CSRF,
  `ModuleAccess::MARKETPLACE_WRITE`, компания только из `getActiveCompany()`.
  Ноль бизнес-логики: диспатч и redirect.
- `JobType::LISTING_CATALOG_SYNC_OZON` + `MarketplaceJobLog` на каждый прогон:
  `running` → `done` со счётчиками `products_fetched` / `listings_upserted` /
  `raw_records_stored`, либо `failed`. Итог последнего прогона виден рядом с
  кнопкой — без этого кнопка работает вслепую.
- В `summary.error` кладётся **класс** исключения, а не текст: сообщение
  внешнего API может нести данные продавца. Формат один для всех ошибок,
  включая 429.

**Заодно закрыт FOLLOW-UP 2 из Stage 2** — взаимное исключение прогонов.
Блокировка `LockFactory` по `(companyId, connectionId)`, TTL 300 с с продлением
на границах страниц и чанков: `RefreshOzonListingCatalogAction` принимает
прогресс-колбэк, обработчик делает в нём `refresh()`. Короткий TTL не запирает
подключение после аварийного воркера, продление не даёт lease протухнуть посреди
живого обхода крупного каталога.

Проверки «прогон уже идёт» в контроллере нет намеренно: между проверкой и
диспатчем есть окно, то есть это была бы иллюзия защиты. Взаимное исключение
живёт там, где выполняется работа.

### Затронутые файлы

Новые: `SyncOzonListingCatalogController`, `MarketplaceJobLogFailQuery`,
`SyncOzonListingCatalogButtonTest`.

Изменённые: `SyncOzonListingCatalogHandler` (журнал, блокировка, устойчивая
запись провала), `RefreshOzonListingCatalogAction` (прогресс-колбэк),
`MarketplaceListingsController` (последний прогон в шаблон), `JobType`,
`templates/marketplace/listings/index.html.twig`, `ARCHITECTURE.md` (1.85).

Миграций нет: `MarketplaceJobLog` хранит `job_type` строкой, новое значение
enum схемы не меняет.

### Self-review
- [x] Scope compliance — только UI-триггер, журнал и блокировка
- [x] Один контроллер = один action = `__invoke`, ноль бизнес-логики
- [x] Security — CSRF; `IsGranted(MARKETPLACE_WRITE)`; компания из
      `getActiveCompany()`, а не из запроса
- [x] Секреты — ни в шаблон, ни в журнал, ни во flash не попадают
- [x] Логирование — `info` на старт/финиш и на пропуск по блокировке,
      `warning` на 429 и на сбой release, `error` на сбой записи журнала
- [x] `ARCHITECTURE.md` обновлён

### Тесты

11 новых: 6 функциональных (`SyncOzonListingCatalogButtonTest`) и 5
интеграционных в `SyncOzonListingCatalogHandlerTest`, плюс
`testProgressCallbackIsCalledOnPageAndChunkBoundaries` в тесте Action.

Два теста переписаны, потому что зеленели **по неверной причине**:

- `testMemberWithoutWriteAccessIsRejected` сначала слал неверный CSRF-токен —
  403 приходил бы от проверки токена, и про права тест не доказывал бы ничего.
  Теперь берёт валидный токен со страницы, и единственная причина отказа —
  `IsGranted`.
- `testFailedRunIsRecordedAsFailed` содержал `self::fail()` внутри `try`, чей
  собственный `catch (\Throwable)` его же и перехватывал: тест прошёл бы, даже
  перестань обработчик пробрасывать исключение. Исключение сохраняется в
  переменную и проверяется по классу.

### Команды для проверки

| Проверка | Результат |
|---|---|
| `composer test:unit` | OK — 1983 tests, 11042 assertions |
| `composer test:integration` | OK — 1038 tests, 5050 assertions |
| `composer test:functional` | 577 tests, 1 падение — красный baseline |
| `composer cs:check` | Found 0 of 2386, exit 0 |
| `composer cs:strict-types` | Found 0 of 2386, exit 0 |
| `composer stan` (PHPStan level 8) | `[OK] No errors` |

`DashboardSnapshotGoldenTest` падает и без изменений — доказано в Stage 2
прогоном на базовом коммите со спрятанным диффом.

### External review

- Reviewer: Codex CLI 0.151.0 (`codex exec -s read-only --ephemeral`, дифф через stdin)
- Iterations: 3
- Result: **REVIEW_GREEN**, находок в третьей итерации нет

Исправлено по ходу итераций (10 находок принято, 1 отклонена):

| Итерация | Класс | Находка | Что сделано |
|---|---|---|---|
| 1 | IMPORTANT | TTL 3600 без продления — длинный прогон теряет lease | TTL 300 + `refresh()` через прогресс-колбэк |
| 1 | IMPORTANT | сбой в чанковой транзакции закрывает EntityManager, запись провала бросает `EntityManagerClosed` и подменяет исходное исключение | терминальный статус пишется через DBAL |
| 1 | IMPORTANT | `release()` выполнялся раньше `complete()` | терминальный статус до освобождения, `release` защищён |
| 1 | IMPORTANT | 429 писал строку вместо класса исключения | формат унифицирован |
| 1 | MINOR | `self::fail()` внутри `try` | исключение проверяется по классу |
| 1 | MINOR | тестовая блокировка не в `finally` | `finally` + тест повторного `acquire()` |
| 1 | MINOR | `ARCHITECTURE.md` противоречил сам себе | формулировка исправлена |
| 2 | IMPORTANT | создание журнала вне `try/finally`: сбой оставил бы блокировку, а retry отступил бы по ней и подтвердил сообщение без синхронизации | `try` начинается сразу после `acquire()` |
| 2 | IMPORTANT | запись провала не защищена и подменила бы исходное исключение | best-effort с логом класса |
| 2 | MINOR | права `MARKETPLACE_WRITE` не проверены функционально | тест участника с уровнем READ |
| 2 | IMPORTANT | один запуск создаёт несколько журналов по числу подключений | **Отклонено.** `UniqueConstraint('company_id', 'marketplace', 'connection_type')` не допускает больше одного Ozon SELLER-подключения у компании; косвенно — `MarketplaceCredentialsQuery` выбирает креды с `LIMIT 1` |

Самая ценная находка — вторая из первой итерации: посылка проверена в
исходниках Doctrine (`EntityManager::wrapInTransaction()` вызывает `close()` в
`finally` при неуспехе). Собственный тест провала её не ловил, потому что
HTTP 500 приходит до транзакции; добавлен тест, закрывающий EntityManager прямо
из мок-клиента.

### FOLLOW-UP — не сделано в этом Stage

1. **Запись остаётся `running` после SIGKILL/OOM.** `finally` при аварийном
   завершении процесса не выполняется, TTL снимает только ключ блокировки, и UI
   бессрочно показывает несуществующий прогон. Это общее свойство
   `MarketplaceJobLog` для **всех** `JobType` (у существующего
   `BARCODE_SYNC_OZON` то же самое), а не привнесённое этим Stage. Чинить нужно
   разом для всех типов: heartbeat / `leaseExpiresAt` + перевод просроченных в
   терминальный статус + отдельное отображение просроченного состояния.
   Ограничение названо явно в `ARCHITECTURE.md`.
2. Незакрытые FOLLOW-UP Stage 2 остаются в силе — в первую очередь **память
   `O(весь каталог)`**: до первого прогона на продавце с крупным каталогом
   проверить потребление памяти воркера.

### Риски / на что обратить внимание ревьюеру
- Кнопка доступна всем, у кого есть `MARKETPLACE_WRITE`. Один нажатый прогон
  тянет весь каталог продавца — при крупном каталоге это десятки запросов к
  Ozon и минуты работы воркера.
- Блокировка снимает только дублирование; она не ограничивает частоту.
  Пользователь может нажимать кнопку раз в пять минут, и каждый раз это будет
  полноценный обход.

### Открытые вопросы
- нет
