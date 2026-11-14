<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Application\DTO;

use App\MarketplaceEconomics\Domain\SkuMetrics;

final readonly class SkuEconomicsRow
{
    public function __construct(
        public string $listingId,
        public string $sku,
        public ?string $title,
        public ?string $supplierSku,
        public SkuMetrics $current,
        public SkuMetrics $previous,
    ) {
    }
}
