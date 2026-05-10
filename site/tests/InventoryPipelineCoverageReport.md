# Inventory Pipeline Coverage Report

| Компонент | Сценарий | Покрыто | Тестовый файл | Комментарий |
|---|---|---|---|---|
| MarketplaceFacade | возвращает только active Ozon SELLER connections | Да | `site/tests/Integration/Marketplace/Facade/MarketplaceFacadeTest.php` | Покрыто в `testGetActiveOzonSellerConnectionsReturnsOnlySafeActiveOzonSellerConnections`. |
| MarketplaceFacade | не возвращает inactive connections | Да | `site/tests/Integration/Marketplace/Facade/MarketplaceFacadeTest.php` | В этом же тесте есть inactive Ozon SELLER, он отфильтрован. |
| MarketplaceFacade | не возвращает WB | Да | `site/tests/Integration/Marketplace/Facade/MarketplaceFacadeTest.php` | В этом же тесте есть WB SELLER, он отфильтрован. |
| MarketplaceFacade | не возвращает Ozon PERFORMANCE | Да | `site/tests/Integration/Marketplace/Facade/MarketplaceFacadeTest.php` | В этом же тесте есть Ozon PERFORMANCE, он отфильтрован. |
| MarketplaceFacade | фильтрует по companyId | Частично | `site/tests/Integration/Marketplace/Facade/MarketplaceFacadeTest.php` | Проверка company-фильтра есть у `resolveListingsToProducts`, но для `getActiveOzonSellerConnections` отдельного теста с входным `companyId` нет (метод возвращает глобальный список). |
| MarketplaceFacade | не возвращает apiKey/clientSecret/credentials/settings | Да | `site/tests/Integration/Marketplace/Facade/MarketplaceFacadeTest.php` | Проверяется whitelist ключей массива и отсутствие секретных полей. |
| InventorySnapshotSessionListQuery | фильтр по companyId | Да | `site/tests/Integration/Inventory/Infrastructure/Query/InventorySnapshotSessionListQueryTest.php` | `testReturnsOnlyRecordsForRequestedCompany`. |
| InventorySnapshotSessionListQuery | чужая company не видна | Да | `site/tests/Integration/Inventory/Infrastructure/Query/InventorySnapshotSessionListQueryTest.php` | В том же тесте foreign запись не попадает в выборку. |
| InventorySnapshotSessionListQuery | сортировка новые сверху | Да | `site/tests/Integration/Inventory/Infrastructure/Query/InventorySnapshotSessionListQueryTest.php` | `testSortsNewestFirst`. |
| InventorySnapshotSessionListQuery | пагинация через Pagerfanta | Да | `site/tests/Integration/Inventory/Infrastructure/Query/InventorySnapshotSessionListQueryTest.php` | `testProvidesProductionPaginationWithDefaults` проверяет pager-метаданные. |
| InventorySnapshotSessionListQuery | perPage = 30 | Да | `site/tests/Integration/Inventory/Infrastructure/Query/InventorySnapshotSessionListQueryTest.php` | Проверяется `InventorySnapshotSessionListQuery::PER_PAGE` и 30 записей на странице. |
| InventorySnapshotSessionListQuery | raw payload не выбирается | Да | `site/tests/Integration/Inventory/Infrastructure/Query/InventorySnapshotSessionListQueryTest.php` | `testDoesNotSelectRawPayloadColumns`. |
| InventorySnapshotSessionRepository | findLatestActiveByCompanyAndSource() | Да | `site/tests/Integration/Inventory/Repository/InventorySnapshotSessionRepositoryTest.php` | Базовый сценарий покрыт. |
| InventorySnapshotSessionRepository | pending/in_progress считаются active | Да | `site/tests/Integration/Inventory/Repository/InventorySnapshotSessionRepositoryTest.php` | В тесте создаются pending и in_progress; выбирается актуальная active-сессия. |
| InventorySnapshotSessionRepository | terminal statuses не active | Да | `site/tests/Integration/Inventory/Repository/InventorySnapshotSessionRepositoryTest.php` | `testTerminalStatusIsNotActive`. |
| InventorySnapshotSessionRepository | чужая company не видна | Да | `site/tests/Integration/Inventory/Repository/InventorySnapshotSessionRepositoryTest.php` | `testForeignCompanySessionIsNotVisible`. |
| InventorySnapshotSessionRepository | другой marketplace/source не блокирует | Да | `site/tests/Integration/Inventory/Repository/InventorySnapshotSessionRepositoryTest.php` | `testOtherSourceDoesNotBlockRequestedSource`. |
| InventorySnapshotSessionRepository | invalid companyId | Да | `site/tests/Integration/Inventory/Repository/InventorySnapshotSessionRepositoryTest.php` | `testFindLatestActiveByCompanyAndSourceThrowsForInvalidCompanyId`. |
| InventorySnapshotSessionRepository | findByIdAndCompany(), если уже добавлен | Да | `site/tests/Integration/Inventory/Repository/InventorySnapshotSessionRepositoryTest.php` | Покрыты успешный поиск по id+companyId, null для чужой company, invalid id и invalid companyId. |
| RequestOzonInventorySnapshotAction | нет активных Ozon connections | Да | `site/tests/Integration/Inventory/Application/RequestOzonInventorySnapshotActionTest.php` | `testNoActiveConnectionReturnsHasConnectionsFalse`. |
| RequestOzonInventorySnapshotAction | active connection создаёт InventorySnapshotSession | Да | `site/tests/Integration/Inventory/Application/RequestOzonInventorySnapshotActionTest.php` | Проверяется в `testActiveConnectionCreatesSessionAndDispatchesMessage` через результат + side effect. |
| RequestOzonInventorySnapshotAction | active connection dispatch-ит SyncOzonInventorySnapshotMessage | Да | `site/tests/Integration/Inventory/Application/RequestOzonInventorySnapshotActionTest.php` | Проверяется `InMemoryBus`. |
| RequestOzonInventorySnapshotAction | active session guard не создаёт дубль | Да | `site/tests/Integration/Inventory/Application/RequestOzonInventorySnapshotActionTest.php` | `testActiveSessionSkipsDuplicateDispatch`. |
| RequestOzonInventorySnapshotAction | dispatch failure не оставляет pending session навсегда | Да | `site/tests/Integration/Inventory/Application/RequestOzonInventorySnapshotActionTest.php` | `testAllDispatchFailuresMarkSessionFailed`. |
| RequestOzonInventorySnapshotAction | connectionId валидируется | Частично | `site/tests/Integration/Inventory/Application/RequestOzonInventorySnapshotActionTest.php` | Интеграционный сценарий с невалидным connectionId недостижим через реальную модель, потому что MarketplaceConnection валидирует UUID в конструкторе и id хранится как guid. Это зафиксировано отдельным invariant-тестом. |
| RequestOzonInventorySnapshotAction | Action не делает HTTP-запрос к Ozon | Частично | `site/tests/Integration/Inventory/Application/RequestOzonInventorySnapshotActionTest.php` | Косвенно видно по in-memory bus и отсутствию клиента, но прямого guard-теста (например, spy HTTP client) нет. |
| SyncOzonInventorySnapshotMessage | payload scalar-only | Да | `site/tests/Unit/Inventory/Message/SyncOzonInventorySnapshotMessageTest.php` | Проверяется создание сообщения с scalar-полями. |
| SyncOzonInventorySnapshotMessage | companyId | Да | `site/tests/Unit/Inventory/Message/SyncOzonInventorySnapshotMessageTest.php` | Есть explicit assert. |
| SyncOzonInventorySnapshotMessage | connectionId | Да | `site/tests/Unit/Inventory/Message/SyncOzonInventorySnapshotMessageTest.php` | Есть explicit assert. |
| SyncOzonInventorySnapshotMessage | snapshotSessionId | Да | `site/tests/Unit/Inventory/Message/SyncOzonInventorySnapshotMessageTest.php` | Есть explicit assert. |
| SyncOzonInventorySnapshotMessage | triggerType string | Да | `site/tests/Unit/Inventory/Message/SyncOzonInventorySnapshotMessageTest.php` | Есть explicit assert со строкой `manual`. |
| SyncOzonInventorySnapshotMessage | нет apiKey/clientSecret | Да | `site/tests/Unit/Inventory/Message/SyncOzonInventorySnapshotMessageTest.php` | Проверяется `property_exists(...) === false`. |
| OzonInventoryClient | success raw response без нормализации | Да | `site/tests/Unit/Inventory/Infrastructure/Api/Ozon/OzonInventoryClientTest.php` | `testSuccessReturnsRawResponseWithoutNormalizationForV4ProductInfoStocksContract`. |
| OzonInventoryClient | endpoint /v4/product/info/stocks | Да | `site/tests/Unit/Inventory/Infrastructure/Api/Ozon/OzonInventoryClientTest.php` | Проверка `assertStringEndsWith('/v4/product/info/stocks', ...)`. |
| OzonInventoryClient | headers Client-Id / Api-Key | Да | `site/tests/Unit/Inventory/Infrastructure/Api/Ozon/OzonInventoryClientTest.php` | Покрыто в `testCredentialsAndRequestBodyArePassedCorrectly`. |
| OzonInventoryClient | first page не отправляет last_id=null | Да | `site/tests/Unit/Inventory/Infrastructure/Api/Ozon/OzonInventoryClientTest.php` | `testRequestBodyDoesNotContainLastIdWhenCursorIsNull`. |
| OzonInventoryClient | next page отправляет last_id | Да | `site/tests/Unit/Inventory/Infrastructure/Api/Ozon/OzonInventoryClientTest.php` | Покрыто в `testCredentialsAndRequestBodyArePassedCorrectly`. |
| OzonInventoryClient | 400 → OzonInventoryApiException | Да | `site/tests/Unit/Inventory/Infrastructure/Api/Ozon/OzonInventoryClientTest.php` | `testBadRequest400MapsToApiException`. |
| OzonInventoryClient | 401/403 → OzonInventoryApiException | Да | `site/tests/Unit/Inventory/Infrastructure/Api/Ozon/OzonInventoryClientTest.php` | Есть отдельные тесты на 401 и 403. |
| OzonInventoryClient | 429 → OzonInventoryRateLimitException | Да | `site/tests/Unit/Inventory/Infrastructure/Api/Ozon/OzonInventoryClientTest.php` | `testRateLimit429MapsToRateLimitException`. |
| OzonInventoryClient | 5xx → RuntimeException | Да | `site/tests/Unit/Inventory/Infrastructure/Api/Ozon/OzonInventoryClientTest.php` | `testServerError5xxThrowsRetryableRuntimeException`. |
| OzonInventoryClient | invalid JSON | Да | `site/tests/Unit/Inventory/Infrastructure/Api/Ozon/OzonInventoryClientTest.php` | `testInvalidJsonThrowsException`. |
| OzonInventoryClient | empty clientId | Да | `site/tests/Unit/Inventory/Infrastructure/Api/Ozon/OzonInventoryClientTest.php` | `testEmptyClientIdThrowsValidationException`. |
| OzonInventoryClient | empty apiKey | Да | `site/tests/Unit/Inventory/Infrastructure/Api/Ozon/OzonInventoryClientTest.php` | `testEmptyApiKeyThrowsValidationException`. |
| OzonInventoryClient | invalid limit | Да | `site/tests/Unit/Inventory/Infrastructure/Api/Ozon/OzonInventoryClientTest.php` | `testInvalidLimitThrowsValidationException`. |
| SyncOzonInventorySnapshotHandler | session not found → no-op | Да | `site/tests/Integration/Inventory/MessageHandler/SyncOzonInventorySnapshotHandlerTest.php` | `testSessionNotFoundNoOp`. |
| SyncOzonInventorySnapshotHandler | terminal session → no-op | Да | `site/tests/Integration/Inventory/MessageHandler/SyncOzonInventorySnapshotHandlerTest.php` | `testTerminalSessionNoOp`. |
| SyncOzonInventorySnapshotHandler | no credentials → failed | Да | `site/tests/Integration/Inventory/MessageHandler/SyncOzonInventorySnapshotHandlerTest.php` | `testNoCredentialsMarksFailed`. |
| SyncOzonInventorySnapshotHandler | success one page → raw saved + completed | Да | `site/tests/Integration/Inventory/MessageHandler/SyncOzonInventorySnapshotHandlerTest.php` | `testSuccessOnePageSavesRawAndCompleted`. |
| SyncOzonInventorySnapshotHandler | success several pages → several raw snapshots + completed | Да | `site/tests/Integration/Inventory/MessageHandler/SyncOzonInventorySnapshotHandlerTest.php` | `testSuccessSeveralPagesSavesSeveralRawSnapshots`. |
| SyncOzonInventorySnapshotHandler | error before first page → failed без throw | Да | `site/tests/Integration/Inventory/MessageHandler/SyncOzonInventorySnapshotHandlerTest.php` | `testErrorBeforeFirstPageMarksFailedWithoutThrow`. |
| SyncOzonInventorySnapshotHandler | error after first page → partial без throw | Да | `site/tests/Integration/Inventory/MessageHandler/SyncOzonInventorySnapshotHandlerTest.php` | `testErrorAfterFirstPageMarksPartialWithoutThrow`. |
| SyncOzonInventorySnapshotHandler | rate limit before first page → failed без throw | Да | `site/tests/Integration/Inventory/MessageHandler/SyncOzonInventorySnapshotHandlerTest.php` | `testRateLimitBeforeFirstPageMarksFailedWithoutThrow`. |
| SyncOzonInventorySnapshotHandler | requestParams содержит connectionId | Да | `site/tests/Integration/Inventory/MessageHandler/SyncOzonInventorySnapshotHandlerTest.php` | Проверка `getRequestParams()['connectionId']`. |
| SyncOzonInventorySnapshotHandler | connectionId не добавляется отдельной колонкой | Да | `site/tests/Integration/Inventory/MessageHandler/SyncOzonInventorySnapshotHandlerTest.php` | Проверяется, что connectionId есть в requestParams raw snapshot и что в inventory_raw_snapshots отсутствует колонка connection_id через DBAL SchemaManager. |
| Inventory UI GET /inventory/snapshots | страница доступна авторизованному пользователю | Да | `site/tests/Functional/Inventory/Controller/SnapshotIndexControllerTest.php` | `testPageIsAvailableForAuthorizedUserAndShowsOnlyOwnCompanyRows`. |
| Inventory UI GET /inventory/snapshots | список фильтруется по active company | Да | `site/tests/Functional/Inventory/Controller/SnapshotIndexControllerTest.php` | Тест логинит пользователя с active company и проверяет 1 строку своей компании. |
| Inventory UI GET /inventory/snapshots | чужие загрузки не отображаются | Да | `site/tests/Functional/Inventory/Controller/SnapshotIndexControllerTest.php` | Проверка отсутствия `WILDBERRIES` из foreign company. |
| Inventory UI GET /inventory/snapshots | таблица содержит колонки Дата/Маркетплейс/Статус | Да | `site/tests/Functional/Inventory/Controller/SnapshotIndexControllerTest.php` | Явный assert массива заголовков. |
| Inventory UI GET /inventory/snapshots | empty state | Да | `site/tests/Functional/Inventory/Controller/SnapshotIndexControllerTest.php` | `testEmptyStateIsShownWhenNoSessions`. |
| Inventory UI GET /inventory/snapshots | пагинация по 30 | Да | `site/tests/Functional/Inventory/Controller/SnapshotIndexControllerTest.php` | `testPaginationWorksWithThirtyItemsPerPage`. |
| Inventory UI GET /inventory/snapshots | есть POST-форма “Получить остатки” | Да | `site/tests/Functional/Inventory/Controller/SnapshotIndexControllerTest.php` | Проверяются form action/method и кнопка. |
| Inventory UI GET /inventory/snapshots | есть CSRF token | Да | `site/tests/Functional/Inventory/Controller/SnapshotIndexControllerTest.php` | Проверяется hidden `_token`. |
| Inventory UI POST /inventory/snapshots/request | valid CSRF → action запускается, session создаётся, message dispatch | Да | `site/tests/Integration/Inventory/Controller/SnapshotRequestControllerTest.php` | `testValidCsrfRequestsSnapshotAndRedirectsWithSuccessFlash`. |
| Inventory UI POST /inventory/snapshots/request | invalid CSRF → redirect + flash danger, dispatch не происходит | Да | `site/tests/Integration/Inventory/Controller/SnapshotRequestControllerTest.php` | `testInvalidCsrfRedirectsWithDangerFlashAndDoesNotDispatch`. |
| Inventory UI POST /inventory/snapshots/request | нет active Ozon connection → warning flash | Да | `site/tests/Integration/Inventory/Controller/SnapshotRequestControllerTest.php` | `testNoActiveConnectionShowsWarningFlash`. |
| Inventory UI POST /inventory/snapshots/request | active session exists → warning flash | Да | `site/tests/Integration/Inventory/Controller/SnapshotRequestControllerTest.php` | `testActiveSessionExistsShowsAlreadyRunningWarning`. |
| Inventory UI POST /inventory/snapshots/request | route требует авторизации | Да | `site/tests/Integration/Inventory/Controller/SnapshotRequestControllerTest.php` | `testRouteRequiresAuthenticatedOwnerWithActiveCompany` (редирект на login). |
| Inventory UI POST /inventory/snapshots/request | route требует ROLE_COMPANY_OWNER | Да | `site/tests/Integration/Inventory/Controller/SnapshotRequestControllerTest.php` | В том же тесте `ROLE_COMPANY_USER` получает 403. |
| Inventory UI POST /inventory/snapshots/request | controller не делает HTTP-запрос к Ozon | Частично | `site/tests/Integration/Inventory/Controller/SnapshotRequestControllerTest.php` | Косвенно подтверждено проверкой dispatch в transport; отдельного теста-стоппера HTTP нет. |
| Cron command app:inventory:ozon-daily-sync | no connections → SUCCESS | Да | `site/tests/Integration/Inventory/Command/OzonInventoryDailySyncCommandTest.php` | `testNoConnectionsReturnsSuccess`. |
| Cron command app:inventory:ozon-daily-sync | active Ozon SELLER connection → создаётся ScheduledNight session | Да | `site/tests/Integration/Inventory/Command/OzonInventoryDailySyncCommandTest.php` | `testCreatesScheduledNightSessionForActiveSellerConnection`. |
| Cron command app:inventory:ozon-daily-sync | command не зависит от OzonInventoryClient | Частично | `site/tests/Integration/Inventory/Command/OzonInventoryDailySyncCommandTest.php` | Косвенно покрыто (тест не подменяет HTTP), но прямой assertion на отсутствие зависимости отсутствует. |
| Cron command app:inventory:ozon-daily-sync | command вызывает Action, а не Handler/Client напрямую | Нет | — | Нужен integration-тест со spy/trace контейнером на вызов Action и отсутствие прямого handler/client path. |
| Cron command app:inventory:ozon-daily-sync | cron строка есть в docker/cron/app.cron | Да | `docker/cron/app.cron` | Строка команды присутствует. |
| Cron command app:inventory:ozon-daily-sync | cron время 04:05 | Да | `docker/cron/app.cron` | В cron указан `5 4 * * *`. |
| Cron command app:inventory:ozon-daily-sync | cron содержит --no-interaction | Да | `docker/cron/app.cron` | Флаг `--no-interaction` присутствует. |
| Config | Inventory controllers подключены в routes.yaml | Да | `site/config/routes.yaml` | Есть секция `inventory_controllers`. |
| Config | Inventory Twig namespace подключён в twig.yaml | Да | `site/config/packages/twig.yaml` | Есть namespace/path для `templates/inventory`. |
| Config | SyncOzonInventorySnapshotMessage route стоит в async_sync | Да | `site/config/packages/messenger.yaml` | Роутинг сообщения указан в `async_sync`. |
| Config | не используется async_pipeline для внешнего Ozon HTTP | Да | `site/config/packages/messenger.yaml` | Для `SyncOzonInventorySnapshotMessage` выбран `async_sync`, не `async_pipeline`. |

## Итог

Покрытие минимально достаточное для текущего этапа.
Задача 12.1 закрыла обязательные пробелы:
- findByIdAndCompany()
- HTTP 401
- invariant invalid connectionId
- отсутствие connection_id column

Оставшиеся пункты являются дополнительными архитектурными guard-тестами и могут быть вынесены в отдельную задачу только при необходимости.

Оставшиеся частично/дополнительно:
- `RequestOzonInventorySnapshotAction` — прямой anti-HTTP guard (опционально).
- `SnapshotRequestController` — anti-HTTP guard (опционально).
- `OzonInventoryDailySyncCommand` — прямой assertion, что command вызывает Action, а не Handler/Client.
