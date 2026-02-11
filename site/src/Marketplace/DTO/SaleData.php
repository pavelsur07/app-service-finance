<?php

namespace App\Marketplace\DTO;

use App\Marketplace\Enum\MarketplaceType;

readonly class SaleData
{
    public function __construct(
        public MarketplaceType $marketplace,
        public string $externalOrderId,
        public \DateTimeImmutable $saleDate,
        public string $marketplaceSku,
        public int $quantity,
        public string $pricePerUnit,
        public string $totalRevenue,
        public ?array $rawData = null
    ) {}
}
