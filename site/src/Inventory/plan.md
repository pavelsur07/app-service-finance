Получить отчет в разрезе
Листинга / остатко / склад МП / в дороге к клиенту / в дороге от клиента / 





## Анализ отчёта — всё что нужно есть


Ключевые находки:
- UUID → `guid` + `string`, без Symfony Uid
- Messenger → `async_pipeline` транспорт (туда пойдут наши джобы)
- Барcode → метод в фасаде **отсутствует**, нужно добавить
- Scheduler → нет, команды через cron
- Структура модуля → `Entity/`, `Facade/`, `Message/`, `MessageHandler/`, `Repository/`, `Command/` и т.д.

---

## Полный план реализации для Codex

````markdown
# Inventory Module — Implementation Plan

## Context & Constraints
- Symfony modular monolith, module root: `src/Inventory/`
- No cross-module entity imports. Cross-module calls via Facades only.
- `listingId` is `string` UUID everywhere. Doctrine column type: `guid`.
- Queue transport for async jobs: `async_pipeline` (Symfony Messenger).
- Scheduling: external cron calls Symfony Console Commands.
- Table naming: `snake_case` with module prefix → `inventory_*`.
- UUID generation: `ramsey/uuid` (`Uuid::uuid4()->toString()`).
- Timestamps: `DateTimeImmutable`, set via `#[ORM\PrePersist]` / `#[ORM\PreUpdate]`.
- No soft delete.

---

## Prerequisites — Changes Outside Inventory Module

### 1. Add `findListingByBarcode` to MarketplaceFacade

File: `src/Marketplace/Facade/MarketplaceFacade.php`

Add public method:
```php
public function findListingByBarcode(
    string $companyId,
    string $barcode,
    string $marketplace
): ?ActiveListingDTO {
    // delegate to MarketplaceListingBarcodeRepository::findByBarcode()
    // then load the listing and map to ActiveListingDTO
}
```

The repository method already exists:
`MarketplaceListingBarcodeRepository::findByBarcode(string $companyId, string $barcode, MarketplaceType $marketplace): ?MarketplaceListingBarcode`

Map result: `MarketplaceListingBarcode → listing → ActiveListingDTO`.
Use existing DTO mapping patterns from the same Facade class.

---

## Module Structure to Create

```
src/Inventory/
├── Command/
│   └── FetchInventoryCommand.php
├── DTO/
│   ├── WarehouseStockDTO.php
│   └── InventoryReportRowDTO.php
├── Entity/
│   ├── InventoryRaw.php
│   └── InventoryStock.php
├── Enum/
│   └── InventoryRawStatus.php
├── Facade/
│   └── InventoryFacade.php
├── Infrastructure/
│   └── Marketplace/
│       ├── MarketplaceStockClientInterface.php
│       ├── OzonStockClient.php
│       └── WildberriesStockClient.php
├── Message/
│   └── ProcessInventoryRawMessage.php
├── MessageHandler/
│   └── ProcessInventoryRawHandler.php
├── Repository/
│   ├── InventoryRawRepository.php
│   └── InventoryStockRepository.php
└── Service/
    ├── InventoryFetchService.php
    └── InventoryProcessService.php
```

---

## Step 1 — Enum: InventoryRawStatus

File: `src/Inventory/Enum/InventoryRawStatus.php`

```php
<?php

namespace App\Inventory\Enum;

enum InventoryRawStatus: string
{
    case New       = 'new';
    case Queued    = 'queued';
    case Processed = 'processed';
    case Failed    = 'failed';
}
```

---

## Step 2 — Entity: InventoryRaw

File: `src/Inventory/Entity/InventoryRaw.php`

Table: `inventory_raw`

Fields:
```
id            string guid PK
companyId     string guid  (not a relation — just the ID)
marketplace   string       (e.g. 'ozon', 'wildberries')
fetchedAt     DateTimeImmutable
payload       array (json) — raw response as-is
status        string (InventoryRawStatus backed enum, stored as string)
errorMessage  string|null
createdAt     DateTimeImmutable
updatedAt     DateTimeImmutable
```

Rules:
- `payload` stores the raw API response without any transformation.
- `status` default = `InventoryRawStatus::New`.
- Timestamps via `PrePersist` / `PreUpdate` lifecycle callbacks.
- No relations to other entities.

```php
#[ORM\Entity(repositoryClass: InventoryRawRepository::class)]
#[ORM\Table(name: 'inventory_raw')]
#[ORM\HasLifecycleCallbacks]
class InventoryRaw
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'string', length: 64)]
    private string $marketplace;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $fetchedAt;

    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(type: 'string', length: 32)]
    private string $status;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $companyId,
        string $marketplace,
        \DateTimeImmutable $fetchedAt,
        array $payload,
    ) {
        $this->id          = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $this->companyId   = $companyId;
        $this->marketplace = $marketplace;
        $this->fetchedAt   = $fetchedAt;
        $this->payload     = $payload;
        $this->status      = InventoryRawStatus::New->value;
        $this->errorMessage = null;
    }

    // getters + setters for status, errorMessage
    // PrePersist / PreUpdate callbacks for timestamps
}
```

---

## Step 3 — Entity: InventoryStock

File: `src/Inventory/Entity/InventoryStock.php`

Table: `inventory_stock`

Fields:
```
id            string guid PK
companyId     string guid
listingId     string guid  (UUID from Marketplace module — no FK)
marketplace   string
warehouseId   string       (external warehouse ID from marketplace API)
warehouseName string|null
quantity      int
reservedQty   int          (quantity reserved/in transit if API provides it)
stockDate     DateTimeImmutable  (the date this stock snapshot is for)
createdAt     DateTimeImmutable
updatedAt     DateTimeImmutable
```

Unique constraint: `(companyId, listingId, marketplace, warehouseId, stockDate)` —
one record per listing per warehouse per day.

```php
#[ORM\Entity(repositoryClass: InventoryStockRepository::class)]
#[ORM\Table(name: 'inventory_stock')]
#[ORM\UniqueConstraint(
    name: 'uq_inventory_stock',
    columns: ['company_id', 'listing_id', 'marketplace', 'warehouse_id', 'stock_date']
)]
#[ORM\HasLifecycleCallbacks]
class InventoryStock { ... }
```

---

## Step 4 — DTOs

### WarehouseStockDTO
File: `src/Inventory/DTO/WarehouseStockDTO.php`

```php
// Represents one warehouse stock line from marketplace API
readonly class WarehouseStockDTO
{
    public function __construct(
        public string  $barcode,
        public string  $warehouseId,
        public string  $warehouseName,
        public int     $quantity,
        public int     $reservedQty,
    ) {}
}
```

### InventoryReportRowDTO
File: `src/Inventory/DTO/InventoryReportRowDTO.php`

```php
// Represents one row in the inventory report (post-processing)
readonly class InventoryReportRowDTO
{
    public function __construct(
        public string $listingId,
        public string $marketplace,
        public string $warehouseId,
        public string $warehouseName,
        public int    $quantity,
        public int    $reservedQty,
        public \DateTimeImmutable $stockDate,
    ) {}
}
```

---

## Step 5 — Infrastructure: API Clients

### Interface
File: `src/Inventory/Infrastructure/Marketplace/MarketplaceStockClientInterface.php`

```php
interface MarketplaceStockClientInterface
{
    /**
     * Fetch raw stock data from marketplace API.
     * Returns raw payload (array) to be stored as-is in InventoryRaw.
     */
    public function fetchRawStock(array $credentials): array;

    /**
     * Parse stored raw payload into normalized WarehouseStockDTO[].
     */
    public function parseRawPayload(array $payload): array; // WarehouseStockDTO[]

    public function supports(string $marketplace): bool;
}
```

### OzonStockClient
File: `src/Inventory/Infrastructure/Marketplace/OzonStockClient.php`

- Inject `HttpClientInterface`
- `supports()` → returns true for `'ozon'`
- `fetchRawStock(array $credentials)`:
  - Use `client_id` and `api_key` from `$credentials`
  - Call Ozon API: `POST https://api-seller.ozon.ru/v3/product/info/stocks`
  - Return raw response array as-is
- `parseRawPayload(array $payload)`:
  - Parse `$payload['result']['items']`
  - Each item has `offer_id` (= barcode or SKU), `stocks[]` with `type`, `present`, `reserved`, `warehouse_name`
  - Map to `WarehouseStockDTO[]`

### WildberriesStockClient
File: `src/Inventory/Infrastructure/Marketplace/WildberriesStockClient.php`

- Inject `HttpClientInterface`
- `supports()` → returns true for `'wildberries'`
- `fetchRawStock(array $credentials)`:
  - Use `api_key` from `$credentials`
  - Call WB API: `GET https://statistics-api.wildberries.ru/api/v1/supplier/stocks`
  - Param: `dateFrom` = yesterday in `Y-m-d\TH:i:s` format
  - Return raw response array as-is
- `parseRawPayload(array $payload)`:
  - Each item has `barcode`, `warehouseName`, `quantity`, `inWayToClient`, `inWayFromClient`
  - Map to `WarehouseStockDTO[]`

**Credentials retrieval:** Use `MarketplaceFacade::getConnectionCredentials(companyId, MarketplaceType, ConnectionType)`.
Do NOT hardcode credentials. Do NOT store them in Inventory module.

---

## Step 6 — Message

File: `src/Inventory/Message/ProcessInventoryRawMessage.php`

```php
readonly class ProcessInventoryRawMessage
{
    public function __construct(
        public string $inventoryRawId,  // UUID of InventoryRaw entity
        public string $companyId,
        public string $marketplace,
    ) {}
}
```

Messenger routing (add to `config/packages/messenger.yaml`):
```yaml
App\Inventory\Message\ProcessInventoryRawMessage: async_pipeline
```

---

## Step 7 — Services

### InventoryFetchService
File: `src/Inventory/Service/InventoryFetchService.php`

Dependencies:
- `MarketplaceFacade` (to get connections and credentials)
- `MarketplaceStockClientInterface[]` (all registered clients, iterated by `supports()`)
- `InventoryRawRepository`
- `MessageBusInterface`

Logic:
```
fetchAndDispatch(string $companyId, string $marketplace):
  1. Get credentials via MarketplaceFacade::getConnectionCredentials(...)
  2. Find matching client via supports($marketplace)
  3. Call client->fetchRawStock($credentials) → $payload
  4. Create InventoryRaw($companyId, $marketplace, new DateTimeImmutable(), $payload)
  5. Save InventoryRaw (status = New)
  6. Dispatch ProcessInventoryRawMessage($raw->getId(), $companyId, $marketplace)
  7. Update InventoryRaw status → Queued
```

### InventoryProcessService
File: `src/Inventory/Service/InventoryProcessService.php`

Dependencies:
- `InventoryRawRepository`
- `InventoryStockRepository`
- `MarketplaceFacade`
- `MarketplaceStockClientInterface[]`

Logic:
```
process(string $inventoryRawId):
  1. Load InventoryRaw by ID, check status = New|Queued
  2. Find matching client by marketplace
  3. Call client->parseRawPayload($raw->getPayload()) → WarehouseStockDTO[]
  4. For each WarehouseStockDTO:
     a. Call MarketplaceFacade::findListingByBarcode($companyId, $dto->barcode, $marketplace)
     b. If not found → log warning, skip
     c. If found → upsert InventoryStock record
        (companyId, listingId, marketplace, warehouseId, stockDate = today)
        ON DUPLICATE → update quantity, reservedQty, updatedAt
  5. Update InventoryRaw status → Processed
  6. On any exception → status = Failed, errorMessage = $e->getMessage()
```

Upsert strategy: use `INSERT ... ON CONFLICT DO UPDATE` via Doctrine DBAL,
or: load existing by unique key, update if found, create if not.

---

## Step 8 — MessageHandler

File: `src/Inventory/MessageHandler/ProcessInventoryRawHandler.php`

```php
#[AsMessageHandler]
class ProcessInventoryRawHandler
{
    public function __construct(
        private InventoryProcessService $processService,
    ) {}

    public function __invoke(ProcessInventoryRawMessage $message): void
    {
        $this->processService->process($message->inventoryRawId);
    }
}
```

---

## Step 9 — Console Command

File: `src/Inventory/Command/FetchInventoryCommand.php`

```
Command name: app:inventory:fetch

Arguments: none
Options:
  --company=UUID   (optional, if omitted → fetch for all active companies)
  --marketplace=   (optional: 'ozon' | 'wildberries', if omitted → all)

Logic:
  1. Get list of active connections via MarketplaceFacade::getActiveOzonSellerConnections() etc.
  2. For each connection call InventoryFetchService::fetchAndDispatch(companyId, marketplace)
  3. Output: "Dispatched X jobs for Y companies"

This command is called by external cron once per day (e.g. 02:00 UTC).
```

---

## Step 10 — Facade

File: `src/Inventory/Facade/InventoryFacade.php`

Public methods (for other modules to consume):

```php
class InventoryFacade
{
    // Get latest stock snapshot for a listing
    public function getStockForListing(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
    ): array; // InventoryReportRowDTO[]

    // Get stock for multiple listings (bulk)
    public function getStockForListings(
        string $companyId,
        array $listingIds,
        \DateTimeImmutable $date,
    ): array; // listingId => InventoryReportRowDTO[]

    // Get total quantity across all warehouses for a listing on a date
    public function getTotalQuantityForListing(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
    ): int;
}
```

---

## Step 11 — Migrations

Create Doctrine Migration for two tables:

### `inventory_raw`
```sql
CREATE TABLE inventory_raw (
    id            CHAR(36)     NOT NULL,
    company_id    CHAR(36)     NOT NULL,
    marketplace   VARCHAR(64)  NOT NULL,
    fetched_at    DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    payload       JSON         NOT NULL,
    status        VARCHAR(32)  NOT NULL DEFAULT 'new',
    error_message LONGTEXT     DEFAULT NULL,
    created_at    DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    updated_at    DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    PRIMARY KEY (id),
    INDEX idx_inventory_raw_company_marketplace (company_id, marketplace),
    INDEX idx_inventory_raw_status (status),
    INDEX idx_inventory_raw_fetched_at (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### `inventory_stock`
```sql
CREATE TABLE inventory_stock (
    id             CHAR(36)     NOT NULL,
    company_id     CHAR(36)     NOT NULL,
    listing_id     CHAR(36)     NOT NULL,
    marketplace    VARCHAR(64)  NOT NULL,
    warehouse_id   VARCHAR(128) NOT NULL,
    warehouse_name VARCHAR(255) DEFAULT NULL,
    quantity       INT          NOT NULL DEFAULT 0,
    reserved_qty   INT          NOT NULL DEFAULT 0,
    stock_date     DATE         NOT NULL,
    created_at     DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    updated_at     DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_stock (company_id, listing_id, marketplace, warehouse_id, stock_date),
    INDEX idx_inventory_stock_listing_date (listing_id, stock_date),
    INDEX idx_inventory_stock_company_date (company_id, stock_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## Raw Data Retention Policy

**Recommendation:**

| Period | Action |
|---|---|
| 0–90 days | Keep all raw records (hot storage, indexed) |
| 90–365 days | Keep but archive: set `status = archived`, remove from hot queries |
| 365+ days | Delete via scheduled cleanup command |

Implementation:
- Add `app:inventory:cleanup-raw` console command
- Deletes `InventoryRaw` where `fetched_at < NOW() - 365 days`
- Run via cron weekly (not daily — no need)
- Processed data in `inventory_stock` is kept indefinitely (lightweight rows)

---

## Implementation Order

```
1. Migration — create both tables
2. Enum InventoryRawStatus
3. Entities: InventoryRaw, InventoryStock
4. Repositories: InventoryRawRepository, InventoryStockRepository
5. DTOs: WarehouseStockDTO, InventoryReportRowDTO
6. MarketplaceStockClientInterface
7. OzonStockClient + WildberriesStockClient
8. Message: ProcessInventoryRawMessage
9. MessageHandler: ProcessInventoryRawHandler
10. Services: InventoryFetchService, InventoryProcessService
11. Command: FetchInventoryCommand
12. Facade: InventoryFacade
13. Add findListingByBarcode to MarketplaceFacade
14. Register messenger routing in messenger.yaml
15. Register cron for app:inventory:fetch
```

---

## Out of Scope (NOT in this task)

- Report UI / API endpoints
- Authentication / authorization for the Facade
- Retry logic for failed raw records (separate task)
- Multi-warehouse aggregation report (separate task after basic flow works)
````
