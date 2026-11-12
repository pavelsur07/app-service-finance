<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application\DTO;

final readonly class OzonAllSkuPromoSyncResult
{
    /**
     * @param string $total расход кампаний ALL_SKU_PROMO за период
     * @param string $unallocated из него без товара: нет листингов товаров кампании или продаж за период
     */
    public function __construct(
        public int $campaigns,
        public int $days,
        public string $total,
        public string $unallocated,
    ) {
    }
}
