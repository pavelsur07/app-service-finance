<?php

declare(strict_types=1);

namespace App\Inventory\Application\DTO;

use App\Inventory\Enum\StockStatus;
use App\Marketplace\Enum\MarketplaceType;

final readonly class NormalizedStockRow
{
    public function __construct(
        public MarketplaceType $source,
        public string $sourceSku,
        public ?string $sourceOfferId,
        public ?string $fulfillmentType,
        public StockStatus $status,
        public string $quantity,
        public string $reservedQuantity,
        public string $rawSnapshotId,
    ) {
    }
}
