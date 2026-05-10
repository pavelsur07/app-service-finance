# Inventory Pipeline Coverage Report

## InventorySnapshotSessionRepository
- `findByIdAndCompany()`
  - Покрыто: Да
  - Тесты: `site/tests/Integration/Inventory/Repository/InventorySnapshotSessionRepositoryTest.php`

## OzonInventoryClient
- `401/403 -> OzonInventoryApiException`
  - Покрыто: Да
  - Тесты: `site/tests/Unit/Inventory/Infrastructure/Api/Ozon/OzonInventoryClientTest.php`
  - Примечание: есть отдельные проверки для 401 и 403.

## RequestOzonInventorySnapshotAction
- `connectionId` валидируется
  - Покрыто: Частично
  - Тесты: `site/tests/Integration/Inventory/Application/RequestOzonInventorySnapshotActionTest.php`
  - Примечание: сценарий с невалидным `connectionId` через `MarketplaceFacade::getActiveOzonSellerConnections()` недостижим в интеграции, так как `MarketplaceConnection` валидирует UUID в конструкторе (`Assert::uuid`) и поле `id` имеет тип `guid` в БД.

## SyncOzonInventorySnapshotHandler
- `connectionId` хранится только в `requestParams` (без отдельной колонки `connection_id`)
  - Покрыто: Да
  - Тесты: `site/tests/Integration/Inventory/MessageHandler/SyncOzonInventorySnapshotHandlerTest.php`
