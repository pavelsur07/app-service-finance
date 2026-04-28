# ARCHITECTURE.md

## Inventory

### Entity
- Location — справочник локаций: склады МП, приёмки, транзитные виртуальные локации
- InventorySnapshotSession — логическая группа загрузки snapshot
- InventoryRawSnapshot — сырой ответ API, одна страница ответа
- StockSnapshot — нормализованное состояние остатков на дату

### Enum
- MarketplaceType: используется существующий `App\Marketplace\Enum\MarketplaceType`
- LocationType: mp_warehouse, mp_acceptance, mp_in_transit_to_customer, mp_in_transit_from_customer
- StockStatus: available, in_transit_to_customer, in_transit_from_customer, on_acceptance, defect, blocked
- SnapshotSessionStatus: pending, in_progress, completed, partial, failed
- SnapshotTriggerType: scheduled_night, scheduled_day, manual, retry

### Facade
Пока не реализован. Будет отдельной задачей.

### CompanyId pattern
Все Entity Inventory используют `string $companyId`.
