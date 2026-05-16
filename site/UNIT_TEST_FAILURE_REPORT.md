# Unit test failure analysis report

## Errors (8)

1. `WbCostsRawProcessorTest::*` (4 tests) fail with `ClassIsFinalException` for `MarketplaceCostExistingExternalIdsQuery`.
   - **Classification:** проблема в тесте.
   - **Reason:** тесты создают mock через `getMockBuilder(...)->onlyMethods(['execute'])` для класса, который объявлен `final`.

2. `WbReturnsRawProcessorRefundAmountTest::*` (2 tests) and `WbSalesRawProcessorRevenueTest::*` (2 tests) fail with `ClassIsFinalException` for `MarketplaceBarcodeCatalogService`.
   - **Classification:** проблема в тесте.
   - **Reason:** аналогично, тесты пытаются мокать `final` класс напрямую.

## Failures (21)

1. `InventoryEnumsTest::testStockSnapshotMappingStatusHasExpectedValuesCount`
   - **Classification:** проблема в тесте.
   - **Reason:** в enum порядок кейсов `Mapped, Unmapped, Ambiguous`, а тест ожидает `Unmapped, Mapped, Ambiguous`.

2. `OzonInventoryClientTest::testRequestBodyDoesNotContainLastIdWhenCursorIsNull`
   - **Classification:** проблема в тесте.
   - **Reason:** callback `MockHttpClient` использует сигнатуру `(string $method, string $url, array $options)`, но Symfony передаёт объект Request/Response context; из-за несовпадения `$captured` остаётся `null`.

3. `OzonInventoryClientTest::testCredentialsAndRequestBodyArePassedCorrectly`
   - **Classification:** проблема в тесте.
   - **Reason:** тот же дефект захвата параметров в callback, поэтому проверки на `Client-Id`, `Api-Key`, `json` читают `null`.

4. `WbCostsRawProcessorTest::*` (16 тестов на калькуляторы и batch поведение)
   - **Classification:** в основном проблема в тестах; есть 1 потенциально логическая несовместимость контракта external_id.
   - **Reason A (основная):** калькуляторы WB теперь строят `external_id` только при наличии `rrdId/rrd_id` через `WbCostExternalIdBuilder`; при отсутствии возвращается `null`, и калькуляторы отдают пустой результат `[]`. В тестовых данных часто есть только `srid`, поэтому `assertCount(1, $entries)` падает.
   - **Reason B:** ряд тестов ожидает старый формат external_id (`SRID-..._commission`), в то время как текущая логика формирует `wb:{rrdId}:{category}`.

5. `WbFinanceSalesReportClientTest::testFetchDetailedPaginatesByRrdIdUntil204`
   - **Classification:** проблема в тесте.
   - **Reason:** аналогично Ozon-тестам, захват `json.rrdId` в `MockHttpClient` callback выполнен некорректно (получается `null` вместо `0` на первой странице).

6. `ProcessMarketplaceRawDocumentActionTest::testForceReprocessOzonDoesNotCallWbDeleteByRawDocument`
   - **Classification:** проблема в тесте.
   - **Reason:** тест ожидает, что при OZON не будет вызовов `classifierRegistry->get()` и `processorRegistry->get()`, но текущая бизнес-логика для `sales/returns` всегда проходит через классификацию и процессор; ограничение на marketplace есть только для удаления старых записей в блоке `forceReprocess && marketplace === WILDBERRIES`.

## Итог по типам причин

- **Проблема в тестах:** 28 из 29 проблемных кейсов (все 8 errors + 20 failures).
- **Потенциальная проблема в логике/контракте:** 1 кейс (часть ожиданий в `WbCostsRawProcessorTest` по формату `external_id` может означать смену контракта без синхронизации тестов/документации).

## Рекомендуемый план исправлений

1. В тестах перестать мокать `final` классы напрямую:
   - либо использовать реальные инстансы с подменой зависимостей,
   - либо выделить интерфейсы для зависимостей и мокать интерфейсы.
2. Привести тестовые данные WB-калькуляторов к новому контракту (`rrd_id`/`rrdId` обязательно для генерации `external_id`).
3. Обновить ожидания по `external_id` в WB-тестах на формат `wb:{rrdId}:{category}` (если это целевой контракт).
4. Исправить callback в тестах `MockHttpClient` (Ozon/WB client tests) на корректный способ чтения request options.
5. В `ProcessMarketplaceRawDocumentActionTest` изменить ожидания: при OZON не должны вызываться только delete-методы WB-репозиториев, но классификация/обработка для выбранного `kind` остаётся валидной.
