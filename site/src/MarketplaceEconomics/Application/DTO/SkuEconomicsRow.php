<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Application\DTO;

use App\MarketplaceEconomics\Domain\SkuMetrics;
use App\Shared\Domain\AbcClass;

final readonly class SkuEconomicsRow
{
    public function __construct(
        public string $listingId,
        public string $sku,
        public ?string $title,
        public ?string $supplierSku,
        public SkuMetrics $current,
        public SkuMetrics $previous,
        public AbcClass $abcRevenue,
        public ?AbcClass $abcProfit,
        /** Канал листинга (`ozon`, `wildberries`); null — листинг не найден. */
        public ?string $channel = null,
    ) {
    }
}
